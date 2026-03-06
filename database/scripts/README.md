# Excel Multi-Sheet Migration Tool

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
