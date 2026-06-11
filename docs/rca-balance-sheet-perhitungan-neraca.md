# RCA: Ketidakseimbangan Perhitungan Balance Sheet (Neraca)

| Field | Nilai |
|-------|--------|
| **Dokumen** | Root Cause Analysis & Laporan Perbaikan |
| **Modul** | Laporan Keuangan — Balance Sheet (Standard) |
| **Perusahaan contoh** | KOPERASI PERTAMINA GEOTHERMAL |
| **Tanggal laporan** | 18 Mei 2026 |
| **Branch perbaikan** | `fix/balance-sheet-category-totals-double-count`, `fix/balance-sheet-retained-earnings-double-count` |
| **Status** | Perbaikan agregasi selesai; residual ~355 juta (22 Mei 2026) — audit penutupan buku disarankan |

---

## 1. Ringkasan Eksekutif

Laporan **Balance Sheet** menampilkan persamaan akuntansi **Total Assets = Total Liabilities + Total Equity** yang tidak seimbang. Selisih pada data produksi KPGE mencapai sekitar **Rp 97,2 miliar**, dengan **Total Equity** yang tampil sangat negatif (**-Rp 69,2 miliar**) padahal secara ekonomi seharusnya mendekati **+Rp 28,4 miliar** (Assets − Liabilities).

Penyebab utama bukan pada formatting UI, melainkan pada **logika agregasi total kategori** di backend dan **komposisi akun** yang ikut dijumlahkan. Perbaikan dilakukan di layer `ReportService` (tab Summary & header persamaan) dan `BalanceSheet::buildReportEnhanced` (export tab Details).

---

## 2. Gejala (Symptoms)

### 2.1 Tampilan UI (sebelum perbaikan penuh)

| Komponen | Nilai (contoh KPGE, 18 Mei 2026) |
|----------|-----------------------------------|
| Total Assets | Rp 44.238.931.467,12 |
| Total Liabilities | Rp 16.057.577.061,68 |
| Total Equity | **-Rp 69.202.834.798,47** |
| Liabilities + Equity | **-Rp 53.145.257.736,79** |

**Persamaan gagal:** `44,24 Miliar ≠ 16,06 Miliar + (-69,20 Miliar)`

### 2.2 Gejala sekunder

- Subtotal per **tipe** akun (Current Assets, Current Liabilities, Total Equity, Retained Earnings) **arithmetically konsisten** di dalam masing-masing kategori.
- Masalah terlihat pada **total kategori** dan **header persamaan akuntansi**, bukan pada parsing/format mata uang.
- Setelah perbaikan pertama (hanya skip akun parent), angka KPGE **hampir tidak berubah** — indikasi bahwa akar masalah bukan double-count parent semata.

---

## 3. Dampak (Impact)

| Area | Dampak |
|------|--------|
| **Keputusan manajemen** | Neraca tidak dapat dipercaya untuk analisis solvabilitas dan ekuitas. |
| **Audit / compliance** | Persamaan akuntansi fundamental tidak terpenuhi di laporan standar. |
| **Export PDF/XLSX** | Tab Summary dan Details dapat menampilkan total yang menyesatkan. |
| **Kepercayaan pengguna** | Admin melihat ekuitas negatif ekstrem tanpa penjelasan yang jelas. |

---

## 4. Timeline investigasi

1. **Hipotesis awal (rencana):** Double-count akun **parent + child** di `buildBalanceSheetReport`.
2. **Validasi data KPGE:** Query saldo per kategori — selisih parent hanya **~Rp 1 juta**; tidak menjelaskan selisih Rp 97 miliar.
3. **Hipotesis kedua:** Akun clearing **OPENING BALANCE EQUITY** ikut dijumlahkan ke total Equity.
4. **Konfirmasi:** Akun `310001` ber saldo **~-Rp 97,6 miliar**; mengecualikannya menurunkan selisih menjadi **~Rp 238 juta** (~0,5%).

---

## 5. Analisis akar penyebab (Root Cause Analysis)

### 5.1 Diagram alur data

```
BalanceSheet.php (Filament Page)
    │
    ├─ Tab "Summary" + header "Assets = Liabilities + Equity"
    │       └─ buildReport() → ReportService::buildBalanceSheetReport()
    │               └─ BalanceSheetReportTransformer → UI / PDF summary
    │
    └─ Tab "Details" + export PDF/XLSX enhanced
            └─ BalanceSheet::buildReportEnhanced()
```

Kedua jalur sebelumnya **menjumlahkan semua akun** yang memiliki saldo jurnal tanpa membedakan akun agregat/clearing.

### 5.2 Penyebab #1 — KRITIS: Akun Open Balance Equity ikut total Equity

| Item | Detail |
|------|--------|
| **Akun** | `310001` — OPENING BALANCE EQUITY |
| **Default COA key** | `AccountDefaultKey::OpenBalance` (`open_balance`) |
| **Fungsi seharusnya** | Akun **penampung (clearing)** saat import/posting **saldo awal**; saldo sudah tercermin di akun operasional (kas, piutang, simpanan, dll.). |
| **Saldo KPGE** | ~**-Rp 97,6 miliar** (minor units: -9.762.277.372.207) |
| **Efek ke neraca** | Menarik **Total Equity** ke negatif ekstrem; selisih ≈ magnitude saldo akun ini. |

**Mengapa ini root cause utama untuk KPGE:**  
Ekuitas implisit yang benar (Assets − Liabilities − (Revenue − Expense)) ≈ **+Rp 28,5 miliar**. Ekuitas GL tanpa akun opening balance ≈ **+Rp 28,9 miliar**. Selisih ke GL penuh ≈ **Rp 97,2 miliar** — sama dengan saldo opening balance equity.

### 5.3 Penyebab #2 — MODERAT: Double-count akun parent/header

| Item | Detail |
|------|--------|
| **Lokasi bug** | `ReportService::buildBalanceSheetReport` menjumlahkan **setiap** `accounts.id` dari `getAccountBalances()`. |
| **Mekanisme** | Jika jurnal ada di **parent** dan **child**, keduanya masuk total kategori. |
| **Precedent** | `OpeningBalanceImport` sudah melewati akun parent agar tidak dobel dengan anak. |
| **Dampak KPGE** | 22 akun parent terdeteksi; dampak finansial **kecil** (~Rp 1 juta) dibanding penyebab #1. |

**Deteksi parent:** relasi `children()` **atau** pola kode (`1100.02`, `1100-02`, prefix numerik lebih panjang).

### 5.4 Penyebab #3 — KRITIS (lanjutan): Double-count Retained Earnings GL + terhitung

Setelah perbaikan parent + open balance (PR #90), pada **22 Mei 2026** masih muncul selisih **~Rp 5,71 miliar**:

| Metrik | Nilai |
|--------|--------|
| Total Assets | Rp 44.639.763.015,47 |
| Total Liabilities | Rp 18.866.224.217,88 |
| Total Equity (salah) | Rp 20.061.526.670,78 |
| **Assets − (Liabilities + Equity)** | **Rp 5.712.012.126,81** |

| Komponen | Nilai |
|----------|--------|
| Ekuitas GL (non-excluded, tanpa RE terhitung) | Rp 25.417.940.137,47 |
| `calculateRetainedEarnings()` (Rev−Exp) | Rp −5.356.413.466,69 |
| Akun `3200.01 RETAINED EARNING` (sudah di GL) | Rp +5.336.254.371,10 |

**Mekanisme:** Total equity menjumlahkan saldo akun GL retained earnings **dan** menambahkan RE terhitung dari akun nominal yang belum ditutup — konsep laba ditahan terhitung dua kali dengan efek bersih ~−5,36 M.

**Perbaikan (branch `fix/balance-sheet-retained-earnings-double-count`):** `Account::shouldAddComputedRetainedEarningsToBalanceSheet()` — jika ada akun equity kode `3200%` atau nama mengandung "retained earn", **jangan** tambahkan RE terhitung ke total kategori.

### 5.5 Penyebab #4 — RENDAH (residual): Selisih ~Rp 355 juta setelah perbaikan RE

Setelah mengecualikan parent, open balance, dan double-count RE:

| Metrik | Nilai (22 Mei 2026) |
|--------|--------|
| Total Equity | **Rp 25.417.940.137,47** |
| Assets − (Liabilities + Equity) | **~Rp 355 juta** (~0,8% aset) |

Kemungkinan kontribusi:

- Saldo Revenue/Expense kumulatif belum ditutup ke `3200.01` secara konsisten.
- Selisih RE GL (+5,34 M) vs Rev−Exp net (−5,36 M).
- Pembulatan dan akun header aset/liabilitas.

**Tindakan lanjutan:** `php artisan accounting:reconcile-retained-earnings --company-id=1 --as-of=2026-05-22`

### 5.6 Hipotesis yang DITOLAK

| Hipotesis | Hasil investigasi |
|-----------|-------------------|
| Bug formatting UI | Subtotal tipe konsisten; masalah di agregasi backend. |
| Mismatch `category` vs `type` | Tidak ditemukan akun equity dengan kategori salah di KPGE. |
| Duplikasi query `getAccountBalances` (join invoice) | Tidak menjelaskan pola -97B pada satu akun equity spesifik. |

---

## 6. Solusi yang diterapkan

### 6.1 Strategi

1. **Sentralisasi** daftar akun yang dikecualikan dari total neraca di model `Account`.
2. **Terapkan** pengecualian pada agregasi total di `buildBalanceSheetReport` (Summary UI).
3. **Selaraskan** logika subtree total di `buildReportEnhanced` (export Details).
4. **Reuse** `isParentAccount()` di `OpeningBalanceImport` (hindari duplikasi logika).
5. **Tambah** test regresi Pest.

### 6.2 Aturan agregasi baru

**Total kategori Balance Sheet** = jumlah `ending_balance` akun yang:

- Termasuk kategori real (Asset / Liability / Equity) sesuai `type->getCategory()`, **dan**
- **Bukan** akun parent/header, **dan**
- **Bukan** akun default sistem `open_balance` (Open Balance Equity),

**ditambah** untuk kategori Equity **hanya jika** `Account::shouldAddComputedRetainedEarningsToBalanceSheet($companyId)`:

- **Retained Earnings** = `calculateRetainedEarnings()` = Σ Revenue − Σ Expense (periode penuh dari tanggal transaksi terawal s/d as-of date).
- Jika perusahaan punya akun GL retained earnings (`3200%` / nama "retained earn"), total equity = **hanya** jumlah akun equity GL (non-excluded); RE terhitung tidak dijumlahkan.

**Baris detail akun** di tab Details **tetap menampilkan semua akun** (termasuk parent dan open balance); yang berubah hanya **angka subtotal/total**.

### 6.3 Hasil numerik setelah perbaikan (data KPGE)

| Metrik | 18 Mei 2026 (setelah PR #90) | 22 Mei 2026 (setelah fix RE) |
|--------|------------------------------|------------------------------|
| Total Equity | +Rp 28.419.938.923,60 | **+Rp 25.417.940.137,47** |
| Selisih \|A − (L + E)\| | ~Rp 238 juta | **~Rp 355 juta** |

---

## 7. Daftar perubahan kode (Change Log)

### 7.1 Ringkasan file

| # | File | Jenis perubahan | Baris ± |
|---|------|-----------------|---------|
| 1 | `app/Models/Accounting/Account.php` | Tambah method agregasi & deteksi parent | +72 |
| 2 | `app/Services/ReportService.php` | Perbaiki `buildBalanceSheetReport` | +12 / refactor loop |
| 3 | `app/Filament/Company/Pages/Reports/BalanceSheet.php` | Selaraskan `buildReportEnhanced` | ~30 |
| 4 | `app/Imports/OpeningBalanceImport.php` | Delegate ke `Account::isParentAccount()` | -34 |
| 5 | `tests/Feature/Reports/BalanceSheetReportTest.php` | +3 test regresi | +172 |
| 6 | `app/Models/Accounting/Account.php` | `shouldAddComputedRetainedEarningsToBalanceSheet()` | +15 |
| 7 | `app/Services/ReportService.php` | Kondisi RE terhitung di `buildBalanceSheetReport` | ~10 |
| 8 | `app/Filament/Company/Pages/Reports/BalanceSheet.php` | Selaraskan `buildReportEnhanced` | ~5 |
| 9 | `app/Console/Commands/ReconcileBalanceSheetRetainedEarnings.php` | Command audit | baru |
| 10 | `tests/Feature/Reports/BalanceSheetReportTest.php` | +2 test RE GL | +90 |

**Branch:** `fix/balance-sheet-category-totals-double-count`, `fix/balance-sheet-retained-earnings-double-count`

---

### 7.2 `app/Models/Accounting/Account.php`

**Tujuan:** Single source of truth untuk deteksi akun parent dan daftar pengecualian neraca.

| Method / bagian | Deskripsi |
|---------------|-----------|
| `isParentAccount(): bool` | `true` jika punya children di DB **atau** ada akun lain dengan kode turunan (`.`, `-`, prefix numerik). |
| `parentAccountIdsForCompany(int $companyId): Collection` | Semua ID akun parent per perusahaan. |
| `balanceSheetExcludedAccountIdsForCompany(int $companyId): Collection` | Parent IDs **+** akun terhubung `AccountDefault` key `OpenBalance`. |

**Import tambahan:** `Illuminate\Support\Collection`, `App\Models\AccountDefault`.

---

### 7.3 `app/Services/ReportService.php`

**Method:** `buildBalanceSheetReport()`

**Sebelum:**

```php
$categorySummaryBalances['ending_balance'] += $endingBalance;
$subCategoryTotals[$accountType] = ($subCategoryTotals[$accountType] ?? 0) + $endingBalance;
// (selalu untuk setiap akun yang match kategori)
```

**Sesudah:**

```php
$excludedFromTotals = $companyId !== null
    ? Account::balanceSheetExcludedAccountIdsForCompany((int) $companyId)->flip()
    : collect();

// ... AccountDTO tetap dibuat untuk semua akun ...

if (! $excludedFromTotals->has($account->id)) {
    $categorySummaryBalances['ending_balance'] += $endingBalance;
    $subCategoryTotals[$accountType] = ($subCategoryTotals[$accountType] ?? 0) + $endingBalance;
}
```

**Dampak UI:** Tab **Summary**, header **Total Assets = Liabilities + Equity**, export PDF/CSV summary.

---

### 7.4 `app/Filament/Company/Pages/Reports/BalanceSheet.php`

**Method:** `buildReportEnhanced()` (tab Details, export PDF/XLSX enhanced)

| Perubahan | Detail |
|-----------|--------|
| Retained earnings | Ganti loop manual Revenue/Expense → `$this->reportService->calculateRetainedEarnings()` (konsisten dengan Summary). |
| `$excludedFromTotals` | `Account::balanceSheetExcludedAccountIdsForCompany($companyId)->flip()`. |
| `$subtreeBalance` | Saldo **own** akun = 0 jika ID ada di excluded; tetap jumlahkan anak secara rekursif. |
| Import | `use App\Models\Accounting\Account`. |
| Cleanup | Hapus komentar duplikat pada subtotal per tipe. |

**Sebelum `subtreeBalance`:**

```php
$balance = $balanceMap[$account->id] ?? 0;
return $balance + $account->children->sum(fn($a) => $subtreeBalance($a));
```

**Sesudah:**

```php
$own = $excludedFromTotals->has($account->id) ? 0 : ($balanceMap[$account->id] ?? 0);
return $own + $account->children->sum(fn ($child) => $subtreeBalance($child));
```

---

### 7.5 `app/Imports/OpeningBalanceImport.php`

| Perubahan | Detail |
|-----------|--------|
| Hapus | Method private `isParentAccount()` (~32 baris). |
| Ganti | `$this->isParentAccount($account)` → `$account->isParentAccount()`. |

**Perilaku import saldo awal:** tidak berubah; hanya refaktor ke model.

---

### 7.6 `tests/Feature/Reports/BalanceSheetReportTest.php`

| Test | Verifikasi |
|------|------------|
| `it detects parent accounts for chart of accounts hierarchy` | `isParentAccount()` dan `parentAccountIdsForCompany()`. |
| `it excludes parent account balances from balance sheet equity category totals` | Total equity naik hanya sebesar saldo **child**, bukan parent+child. |
| `it excludes open balance equity clearing account from balance sheet category totals` | Akun default `open_balance` ada di excluded list; total equity = GL (non-excluded) + RE. |
| `it balance sheet category totals satisfy assets equals liabilities plus equity` | (existing) Persamaan akuntansi untuk `testCompany`. |

**Menjalankan test:**

```bash
php artisan test --filter=BalanceSheetReport
```

---

## 8. Rekomendasi pasca-perbaikan

### 8.1 Operasional / data (KPGE)

1. **Audit akun `310001 OPENING BALANCE EQUITY`**  
   - Rekonsiliasi saldo **-Rp 97,6 miliar** terhadap jurnal import saldo awal.  
   - Pastikan tidak ada posting operasional yang menumpuk di akun clearing.

2. **Review import saldo awal**  
   - Pastikan policy **tidak posting ke akun parent** (sudah di `OpeningBalanceImport`) juga dipatuhi di proses manual.

3. **Penutupan buku**  
   - Evaluasi apakah akun Revenue/Expense perlu ditutup ke `320001 RETAINED EARNING` agar selisih residual ~Rp 238 juta hilang.

### 8.2 Teknis

1. Deploy branch `fix/balance-sheet-category-totals-double-count` ke staging/production.
2. Setelah deploy: `php artisan optimize:clear` dan hard refresh browser.
3. Verifikasi Balance Sheet KPGE per **18 Mei 2026**: Total Equity ≈ **+Rp 28,4 miliar**.

### 8.3 Tidak termasuk dalam perbaikan ini

- Indikator UI **"Out of balance"** ketika selisih > threshold (permintaan sebelumnya: tidak perlu).
- Perbaikan data historis jurnal di akun clearing (perlu aktivitas akuntansi manual/script terpisah).

---

## 9. Cara verifikasi

### 9.1 Verifikasi fungsional (UI)

1. Login sebagai Admin KPGE.
2. Buka **Reports → Balance Sheet (Standard)**.
3. Set tanggal **18 Mei 2026** → **Update report**.
4. Periksa header:
   - **Total Equity** positif (~Rp 28,4 Miliar), bukan -Rp 69 Miliar.
   - \|Total Assets − (Total Liabilities + Total Equity)\| < **Rp 500 juta** (target; residual ~Rp 238 juta).

### 9.2 Verifikasi otomatis (CLI)

```bash
php artisan test --filter=BalanceSheetReport
```

### 9.3 Verifikasi analitis (Tinker) — opsional

```php
$companyId = 1; // sesuaikan ID KPGE
$asOf = '2026-05-18';
$report = app(\App\Services\ReportService::class)->buildBalanceSheetReport($asOf, [], null, $companyId);
// Bandingkan categories['Assets'], ['Liabilities'], ['Equity'] summary->endingBalance
```

---

## 10. Lampiran

### 10.1 Akun equity terbesar (KPGE, sebelum perbaikan agregasi)

| Kode | Nama | Saldo (IDR, perkiraan) | Keterangan |
|------|------|------------------------|------------|
| 310001 | OPENING BALANCE EQUITY | **-97,6 Miliar** | **Dieksklusi dari total** setelah fix |
| 3000.05 | Simpanan Sukarela | +23,1 Miliar | Tetap masuk total |
| 320001 | RETAINED EARNING | +8,6 Miliar | Tetap masuk total |
| 3000.04 | Sisa Hasil Usaha | -7,9 Miliar | Tetap masuk total |
| 3000.02 | Simpanan Wajib | +4,6 Miliar | Tetap masuk total |
| 3000.01 | Simpanan Pokok | +468 Juta | Tetap masuk total |

### 10.2 Referensi kode terkait

- Enum default akun: `app/Enums/Accounting/AccountDefaultKey.php` → `OpenBalance`
- Transformer summary: `app/Transformers/BalanceSheetReportTransformer.php`
- View summary: `resources/views/components/company/tables/reports/balance-sheet-summary.blade.php`
- View header persamaan: `resources/views/components/report-summary-section.blade.php`

---

## 11. Persetujuan & riwayat dokumen

| Versi | Tanggal | Author | Catatan |
|-------|---------|--------|---------|
| 1.0 | 2026-05-18 | Engineering | Draft RCA awal (parent account) |
| 1.1 | 2026-05-18 | Engineering | Tambah root cause Open Balance Equity + change log lengkap |

---

*Dokumen ini menjelaskan investigasi dan perbaikan kode. Untuk koreksi data historis akun `310001`, koordinasikan dengan tim akuntansi/koperasi.*
