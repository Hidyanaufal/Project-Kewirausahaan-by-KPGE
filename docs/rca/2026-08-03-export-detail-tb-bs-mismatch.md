# RCA: Export Detail ≠ Trial Balance / Balance Sheet (Saldo Awal)

| Field | Nilai |
|-------|--------|
| **Dokumen** | Root Cause Analysis & Rencana Perbaikan |
| **Modul** | Laporan Keuangan — Trial Balance export Advance / Classic; Balance Sheet Details |
| **Tanggal laporan** | 3 Agustus 2026 |
| **Status** | Perbaikan: satukan sumber data export ke `AccountService::getAccountBalances` / `ReportService` |

---

## 1. Ringkasan eksekutif

Angka **Trial Balance** dan **Balance Sheet** di layar sudah selaras sebagai acuan validasi impor saldo awal. Export **detail** (terutama TB template Advance: Op Bal / Change / End Bal) memakai query SQL terpisah dengan cutoff **date-only** (`toDateString()` → midnight), tanpa soft-delete, dan kalkulasi ending sendiri. Jurnal saldo awal di-post di **`FY start − 1 detik`** (contoh `2025-12-31 23:59:59`) masuk laporan layar (`posted_at <= endOfDay`) tetapi bisa terlewat atau salah kolom di export Advance.

---

## 2. Gejala (Symptoms)

- TB/BS UI (acuan impor saldo awal) sudah clear / balance.
- Export TB Advance (PDF/XLSX) menampilkan Op Bal / Change / End Bal yang **tidak sama** dengan Debit/Credit di UI.
- Baris TOTAL Advance bisa **100× terlalu kecil** vs sum baris (double `/100`).
- Classic export rawan skala salah karena parse string money terformat lalu `/100` lagi.

---

## 3. Dampak (Impact)

| Area | Dampak |
|------|--------|
| **Validasi saldo awal** | Pengguna membandingkan Excel/OB dengan export detail yang tidak mewakili GL yang sama dengan UI. |
| **Audit / compliance** | Dokumen PDF/XLSX tidak dapat diandalkan sebagai salinan TB resmi. |
| **Kepercayaan pengguna** | “Laporan sudah benar, export masih beda” — gejala dual path. |

---

## 4. Alur data (dua jalur)

```
Impor OB → journal posted_at = FY start − 1s
                │
                ▼
         journal_entries + transactions
                │
        ┌───────┴────────┐
        ▼                ▼
 ReportService      __buildReportAdvance (lama)
 getAccountBalances   raw SQL + toDateString()
 endOfDay + soft-delete   midnight, no soft-delete
        │                │
        ▼                ▼
     TB/BS UI        Export detail (mismatch)
```

---

## 5. Analisis akar penyebab

### 5.1 KRITIS — Cutoff datetime vs date-only (TB Advance)

- UI / `buildTrialBalanceReport`: `asOf` di-`endOfDay()` → `posted_at <= 'Y-m-d 23:59:59'`.
- Advance lama: `$end->toDateString()` / `$start->toDateString()` di `whereBetween` / `<` → efektif **`Y-m-d 00:00:00`**.
- OB import: `OpeningBalanceNameImportService` mem-post di `fyStart->subSecond()`.

Akibat: pada as-of hari cutover, jurnal `23:59:59` hilang dari periode Advance; pada rentang lebih panjang sering masuk Change tetapi tidak Op Bal, atau End Bal beda dari UI.

### 5.2 Soft-delete tidak diterapkan (TB Advance)

`getAccountBalances` memfilter `deleted_at` IS NULL pada JE dan transaksi. Advance lama tidak — soft-deleted masih ikut agregasi.

### 5.3 Duplikasi jalur perhitungan

Perbaikan cutover / exclusion di `ReportService` tidak otomatis ke Advance. Ending Advance memakai `(opD−opC)+(chgD−chgC)` tanpa `calculateTrialBalancesForAccount`.

### 5.4 Double `/100` pada baris TOTAL Advance

Akumulator totals sudah dalam major units; baris TOTAL membagi `/100` lagi.

### 5.5 Filter `archived` hanya di Advance

UI tidak memfilter `archived`; Advance memfilter → set akun bisa beda.

### 5.6 Classic — parse money terformat + `/100`

`formatCentsToMoney` sudah major units; `toNumber(...) / 100` mengecilkan angka.

### 5.7 Balance Sheet Details — hierarki vs tipe akun

`buildReportEnhanced` mengelompokkan per `AccountType` lalu menelusuri **semua** anak COA di bawah parent. Akun **ContraAsset** yang `parent_id`-nya mengarah ke parent **CurrentAsset** (contoh: Penyisihan Provisi Kerugian Piutang di bawah Piutang) ikut ditampilkan dan dijumlahkan di Current Asset, sehingga:

- nilai penyisihan **ditambah** ke Total Current asset (harusnya di Contra Assets lalu **dikurangi** dari Total ASSET);
- Total ASSET export = UI + 2× saldo penyisihan.

UI Summary (`buildBalanceSheetReport`) mengelompokkan tiap akun menurut **tipenya sendiri**, tanpa mengikat anak ke tipe parent.

---

## 6. Perbaikan

1. Refactor `__buildReportAdvance` → `getAccountBalances` + `calculateAccountBalances` + `calculateTrialBalancesForAccount`; datetime penuh; tanpa filter archived; TOTALS `/100` sekali.
2. Classic: konversi money via `CurrencyConverter::convertToFloat` tanpa `/100` ekstra.
3. Tes regresi: OB di `23:59:59`, parity ending Advance vs `buildTrialBalanceReport`, TOTALS = sum baris.
4. BS Details: walker hierarki hanya anak **tipe sama**; akun tipe beda dari parent jadi root di section tipenya; Total ASSET tetap Current − Contra + Non-Current.
5. Tes regresi: ContraAsset child di bawah CurrentAsset parent → section Contra + Total ASSET = Summary.
---

## 7. File terkait

- `app/Filament/Company/Pages/Reports/TrialBalance.php`
- `app/Services/AccountService.php` (`getAccountBalances`)
- `app/Services/ReportService.php` (`buildTrialBalanceReport`)
- `app/Services/OpeningBalanceNameImportService.php` (posted_at OB)
- `app/Filament/Company/Pages/Reports/BalanceSheet.php` (`buildReportEnhanced`)
- `tests/Feature/Reports/TrialBalanceReportTest.php`
