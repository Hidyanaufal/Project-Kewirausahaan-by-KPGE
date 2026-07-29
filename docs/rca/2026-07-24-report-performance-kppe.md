# RCA: Performa Lambat Report (Trial Balance / Balance Sheet / Income Statement) — KPPE

| Field | Nilai |
|-------|--------|
| **Dokumen** | Root Cause Analysis & Laporan Perbaikan |
| **Modul** | Laporan Keuangan — TB, Balance Sheet, Income Statement |
| **Perusahaan contoh** | KPPE (company dengan volume jurnal / COA besar) |
| **Tanggal** | 24 Juli 2026 |
| **Status** | Corrective action diterapkan |

---

## 1. Ringkasan Eksekutif

Setelah switch company ke **KPPE**, membuka **Trial Balance**, **Balance Sheet**, **Income Statement** (dan report sejenis) terasa sangat lambat hingga browser tampak berhenti / “Page Unresponsive”.

Ini **bukan error SQL / exception**, melainkan **request Livewire yang terlalu lama** karena:

1. Agregasi seluruh jurnal company diulang beberapa kali per load laporan.
2. Deteksi akun parent/header memakai pola **N+1** (EXISTS per akun).
3. Query saldo selalu `LEFT JOIN` invoices/bills/projects meskipun filter project tidak dipakai.

Corrective action menarget path shared di `Account`, `AccountService`, dan `ReportService` agar satu load laporan memakai jauh lebih sedikit query berat.

---

## 2. Gejala (Symptoms)

- Switch company → KPPE → buka menu report (TB / BS / IS) → UI hang / timeout.
- Tidak ada stack trace error query yang konsisten; request tetap “pending” lama.
- Company dengan volume jurnal lebih kecil relatif lebih cepat (gejala skalable dengan data).

---

## 3. Impact

| Area | Dampak |
|------|--------|
| Operasional | User tidak bisa membuka laporan keuangan KPPE secara normal |
| Produktivitas | Blocking untuk closing / audit / review saldo |
| Stabilitas | Risiko timeout PHP / proxy / Livewire; tab browser freeze |

---

## 4. Root Cause

### 4.1 Primary — agregasi full-history berulang

`AccountService::getAccountBalances()` meng-JOIN `journal_entries` + `transactions` sampai `asOf`, dengan beberapa `CASE SUM`, lalu (sebelum perbaikan) **selalu** LEFT JOIN dokumen multichannel.

Pemakaian per laporan (sebelum perbaikan):

| Laporan | Round-trip agregasi saldo |
|---------|---------------------------|
| Trial Balance | 1× (+ 2× lagi jika post-closing RE) |
| Balance Sheet | 1× + **2×** `calculateRetainedEarnings` (Revenue & Expense) |
| Income Statement | **3×** (Revenue, COGS, Expense) |

Semakin banyak jurnal KPPE, semakin lambat secara linear (atau lebih buruk tanpa indeks yang tepat).

### 4.2 Primary — N+1 deteksi parent

`Account::parentAccountIdsForCompany()` memuat semua akun, lalu per baris memanggil `isParentAccount()` yang bisa menjalankan `children()->exists()` dan/atau `LIKE code%` EXISTS.

Dipakai tiap load TB (`trialBalanceExcludedAccountIdsForCompany`) dan BS (`balanceSheetExcludedAccountIdsForCompany`). COA besar → ratusan–ribuan query ekstra → hang.

### 4.3 Secondary

- `getEarliestTransactionDate()` memakai `MIN(posted_at)` **global** (tidak di-scope company).
- Path default report memakai OR filter ke `invoices.company_id` / `bills.company_id` / `transactions.company_id` setelah LEFT JOIN berat — mempersulit plan query.
- Tidak ada cache hasil laporan per company; setiap apply filter = cold path.

### 4.4 Bukan penyebab utama

- Cache stale antar company (tidak ada Redis report cache).
- Import opening balance (tidak dijalankan saat buka report).
- Koreksi akuntansi contra / OBE / CYE (overhead CPU kecil dibanding agregasi JE).

---

## 5. Timeline

| Waktu | Kejadian |
|-------|----------|
| Sebelum 24 Jul 2026 | Path report sudah mahal untuk company besar; koreksi akuntansi menambah frekuensi exclude parent di TB |
| 24 Jul 2026 | Investigasi: bottleneck di `getAccountBalances`, RE ganda, parent N+1, LEFT JOIN default |
| 24 Jul 2026 | Corrective action: set-based parent, single-pass RE/IS, slim default query, indeks, RCA |

---

## 6. Corrective Action

### 6.1 Set-based parent detection

- `parentAccountIdsFromCollection()`: parent via `parent_id` + deteksi prefix kode di memory (tanpa EXISTS per akun).
- `trialBalanceExcludedAccountIdsForCompany` / `balanceSheetExcludedAccountIdsForCompany` reuse satu load akun (tidak N+1 + tidak double `get()` untuk filter nama RE).

### 6.2 Single-pass saldo

- `calculateRetainedEarnings(..., $accounts = null)` menerima collection pre-loaded.
- Balance Sheet & post-closing TB: **satu** `getAccountBalances`, RE dihitung di memory.
- Income Statement: **satu** agregasi, filter Revenue / COGS / Expense di PHP.

### 6.3 Slim default balance query

- Path tanpa filter project/projectType: filter `transactions.company_id` + exclude invoice cancelled via `NOT EXISTS` (tanpa LEFT JOIN 3 tabel).
- Multichannel LEFT JOIN hanya jika filter project/projectType aktif.
- `getEarliestTransactionDate(?int $companyId)` di-scope company.

### 6.4 Indeks

Migration `2026_07_24_000000_add_report_balance_indexes`:

- `transactions (company_id, posted_at)`
- `journal_entries (company_id, account_id)`

### 6.5 Verifikasi

- Feature tests BS/TB/IS (termasuk parent code-prefix set-based + assert BS memakai 1 agregasi saldo).
- Jalankan migration indeks di environment yang terdampak.

---

## 7. Prevention

1. Hindari pola “load semua akun → per row EXISTS” untuk metadata COA; prefer set-based / flag `is_header` jika hierarki makin kompleks.
2. Untuk laporan as-of / periode, target **satu** agregasi jurnal per request UI; turunan (RE, kategori) dihitung di memory.
3. Jangan LEFT JOIN dokumen opsional pada path hot query kecuali filter benar-benar membutuhkannya.
4. Pertimbangkan follow-up: snapshot saldo periodik / cache report keyed by `company_id + type + dates` bila volume KPPE terus tumbuh.
5. Saat menambah filter report, ukur query count & waktu pada company terbesar (bukan hanya fixture test).

---

## 8. File terkait

| File | Peran |
|------|--------|
| `app/Models/Accounting/Account.php` | Parent detection set-based; exclude TB/BS |
| `app/Services/AccountService.php` | Query balances ramping; earliest date per company |
| `app/Services/ReportService.php` | Single-pass RE / IS / BS / TB |
| `app/Filament/Company/Pages/Reports/BalanceSheet.php` | Export enhanced reuse collection RE |
| `app/Filament/Company/Pages/Reports/TrialBalance.php` | Earliest date scoped company |
| `database/migrations/2026_07_24_000000_add_report_balance_indexes.php` | Indeks pendukung |
| `tests/Feature/Reports/BalanceSheetReportTest.php` | Regresi semantik + query count |
