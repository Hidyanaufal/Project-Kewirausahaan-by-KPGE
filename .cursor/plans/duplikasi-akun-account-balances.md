# Rencana: Hilangkan Duplikasi Akun di Laporan Account Balances

## Ringkasan

Laporan Account Balances masih menampilkan akun yang duplikat (mis. "Bank Mandiri" dua baris dengan saldo berbeda). Rencana ini mengatasi duplikasi dengan menggabungkan baris yang memiliki **nama akun sama** menjadi satu baris dan menjumlahkan saldonya.

## Penyebab duplikasi

- Data dari [ReportService::buildAccountBalanceReport](app/Services/ReportService.php) memanggil `AccountService::getAccountBalances()` yang mengembalikan **satu baris per `accounts.id`** (`groupBy(['accounts.id', ...])`).
- Duplikasi muncul karena di database ada **beberapa record akun dengan nama sama** (mis. dua akun "Bank Mandiri" dengan id berbeda). Bukan bug query, melainkan duplikasi di chart of accounts.

## Keamanan mengubah ReportService

- **Satu-satunya pemanggil `buildAccountBalanceReport`:** [AccountBalances.php](app/Filament/Company/Pages/Reports/AccountBalances.php) (baris 86).
- Laporan lain (BalanceSheet, TrialBalance, IncomeStatement, CashFlow, dll.) memakai method lain: `buildBalanceSheetReport`, `buildTrialBalanceReport`, dll. **Tidak ada laporan lain yang memanggil `buildAccountBalanceReport`.**
- Jadi perubahan hanya di dalam `buildAccountBalanceReport` **tidak mengganggu** laporan lain.

## Pilihan merge key

- **By name (nama akun):** Akun dengan nama sama digabung (mis. dua "Bank Mandiri" → satu baris, saldo dijumlah). Rekomendasi untuk kasus duplikat seperti di screenshot.
- **By code (kode akun):** Bisa dipakai jika ingin membedakan akun yang namanya sama tapi kode beda. Untuk kasus "Bank Mandiri" dua kali, merge by name yang diinginkan.

Rencana implementasi memakai **merge by name** (nama akun). Jika perlu by code, bisa diganti nanti.

## Rencana implementasi

**Lokasi:** [app/Services/ReportService.php](app/Services/ReportService.php), method `buildAccountBalanceReport` (sekitar baris 61–121).

**Alur saat ini (ringkas):**

- `$accountsInCategory = $accounts->where('category', $category);`
- Loop `foreach ($accountsInCategory as $account)`: hitung balance, tambah ke summary, buat satu `AccountDTO` per akun.

**Perubahan:**

1. Setelah `$accountsInCategory = $accounts->where('category', $category);`, **group by nama akun** (gunakan key yang konsisten, mis. `trim($account->name)` agar spasi tidak bikin beda group).
2. Loop per **group** (bukan per akun):
   - Inisialisasi satu set raw balance (starting_balance, debit_balance, credit_balance, net_movement, ending_balance) = 0.
   - Untuk setiap akun di group: panggil `calculateAccountBalances($account)`, **jumlahkan** tiap field ke raw balance, dan tambahkan juga ke `$categorySummaryBalances` (agar total per kategori tetap benar).
   - Panggil `formatBalances()` **sekali** untuk raw balance yang sudah dijumlah.
   - Buat **satu** `AccountDTO` untuk group tersebut (nama/code/id/dates ambil dari akun pertama di group; balance pakai hasil format tadi), append ke `$categoryAccounts`.
3. Perhitungan `$reportTotalBalances`, `formattedCategorySummaryBalances`, dan `AccountCategoryDTO` / `ReportDTO` **tidak diubah** (tetap dari `$categorySummaryBalances` yang sudah diisi dari tiap akun di group, jadi total tetap sama).

**Struktur kode (pseudocode):**

```text
foreach ($orderedCategories as $category) {
    $accountsInCategory = $accounts->where('category', $category);
    $groupedByName = $accountsInCategory->groupBy(fn ($a) => trim($a->name));

    foreach ($groupedByName as $accountName => $accountGroup) {
        $rawBalances = ['starting_balance' => 0, 'debit_balance' => 0, 'credit_balance' => 0, 'net_movement' => 0, 'ending_balance' => 0];

        foreach ($accountGroup as $account) {
            $accountBalances = $this->calculateAccountBalances($account);
            foreach ($relevantFields as $field) {
                $rawBalances[$field] += $accountBalances[$field];
                $categorySummaryBalances[$field] += $accountBalances[$field];
            }
        }

        $formattedAccountBalances = $this->formatBalances($rawBalances);
        $first = $accountGroup->first();
        $categoryAccounts[] = new AccountDTO($first->name, $first->code, $first->id, $formattedAccountBalances, ...);
    }

    // sisanya seperti sekarang: format category summary, report total, AccountCategoryDTO, ReportDTO
}
```

**Tidak ada perubahan di:**

- [AccountBalances.php](app/Filament/Company/Pages/Reports/AccountBalances.php)
- [AccountBalanceReportTransformer.php](app/Transformers/AccountBalanceReportTransformer.php)
- ExportService (tetap pakai flatten `['name']` yang sudah ada)

## Ringkasan

- **Masalah:** Akun dengan nama sama tampil beberapa baris (duplikat) di laporan Account Balances.
- **Solusi:** Di `ReportService::buildAccountBalanceReport`, group akun per kategori **by name**, jumlahkan saldo per group, tampilkan satu baris per nama dengan saldo gabungan.
- **Dampak:** Hanya laporan Account Balances (satu-satunya pemanggil `buildAccountBalanceReport`). Laporan lain tidak terpengaruh.
- **Merge key:** Nama akun (trimmed). Total per kategori dan total keseluruhan tetap benar karena summary tetap dihitung dari semua akun.
