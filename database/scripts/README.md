# Excel Multi-Sheet Migration Tool

> **DEPRECATED** — Script Python ini **tidak lagi digunakan** untuk import invoice.
> Import invoice legacy harus melalui Laravel agar jurnal akuntansi (approval + payment) ter-generate dengan benar.

## Jalur import resmi

```bash
# Via UI: Projects → Import Legacy Project & Invoice

# Via CLI (re-import / perbaikan data):
php artisan legacy:reimport-invoices {companyId} \
  --file="Copy File of Master Project & Invoice - kpg(1).xlsx" \
  --only-broken

# Preview tanpa perubahan:
php artisan legacy:reimport-invoices {companyId} --file="..." --dry-run

# Audit selisih sheet Invoice vs supplement (t):
php artisan legacy:invoice-sheet-mismatch audit \
  --file="Copy File of Master Project & Invoice - kpg(1).xlsx" \
  --company={companyId}

# Perbaiki sheet Invoice di Excel agar cocok dengan supplement:
php artisan legacy:invoice-sheet-mismatch fix-excel \
  --file="Copy File of Master Project & Invoice - kpg(1).xlsx" \
  --output="Copy File of Master Project & Invoice - kpg(1)-fixed.xlsx"

# Re-import invoice DB yang totalnya tidak cocok dengan supplement:
php artisan legacy:invoice-sheet-mismatch fix-db \
  --file="Copy File of Master Project & Invoice - kpg(1).xlsx" \
  --company={companyId} \
  --dry-run
```

## Mengapa script ini deprecated?

- Insert SQL langsung ke `invoices` **tanpa** jurnal approval/payment
- Mengalikan nominal `* 100` (salah — sistem menyimpan whole rupiah)
- Tidak compatible dengan model invoice + accounting saat ini

---

A lightweight tool for migrating multi-sheet Excel data (with specific naming patterns) into a MySQL database. Supports sheet order control, dynamic mapping, and validation.

## 🚀 Features

- Read Excel files with multiple sheets.
- Supports sheet naming pattern like `Project-{jenis_projek}` and `Invoice`.
- Custom processing order (e.g., process all `Project-*` before `Invoice`).
- Data transformation & validation layer.
- Insert clean data into MySQL.

## 📦 Tech Stack

- **Python 3.10+**
- **pandas** for Excel reading
- **openpyxl** for XLSX engine
- **SQLAlchemy** for DB operations

## 📁 Project Structure

```
scripts/
├── excel/
├── README.md
├── requirements.txt
├── run.py
└── utils/
    ├── db.py
    ├── helpers.py
    └── parser.py
```

## ⚙️ Installation

```bash
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
```

## 🧩 Configuration

Edit `config.py`:

```python
DB_URL = "mysql+pymysql://user:pass@localhost:3306/dbname"
SHEET_ORDER = ["Project", "Invoice"]
```

## 🛠️ How It Works

1. Load Excel file
2. Detect sheets
3. Sort sheets based on prefix order
4. Map & clean data
5. Insert to MySQL

## ▶️ Run Migration

```bash
python main.py --file data.xlsx
```

## 🧪 Example Sheet Pattern

- `Project-Website`
- `Project-MobileApp`
- `Invoice`

## 🔐 Validation Layer

- Missing required fields
- Data type checks
- Duplicate detection

## 🗄️ Database Output

Data will be inserted into MySQL according to mapping logic inside `mapping/*.py`.

## 📌 Notes

- Sheet names _must_ follow the agreed pattern.
- Only `.xlsx` is supported.

## 📞 Support

Need help? Just ask, bro 😄

test
