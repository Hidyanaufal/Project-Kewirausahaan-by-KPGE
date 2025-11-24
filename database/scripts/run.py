import pandas as pd
from utils.db import engine
from utils.parser import parse_project_sheet, parse_invoice_sheet
from dotenv import load_dotenv
import os

load_dotenv()

COMPANY_ID = int(os.getenv("COMPANY_ID"))
CREATED_BY = int(os.getenv("CREATED_BY"))

# urutan baca sheet
ORDER = [
    "Project-Pengadaan",
    "Invoice"
]

excel_path = "excel/data.xlsx"

xls = pd.ExcelFile(excel_path)

### ========== MIGRATE PROJECTS ==========
if "Project-Pengadaan" in ORDER:
    df = pd.read_excel(excel_path, sheet_name="Project-Pengadaan")
    proj_data = parse_project_sheet(df)

    for p in proj_data:
        # ambil client (lookup atau insert baru)
        client_name = p["client_name"]

        # cek apakah client sudah ada
        client = engine.execute(
            "SELECT id FROM clients WHERE name=%s AND company_id=%s LIMIT 1",
            (client_name, COMPANY_ID)
        ).fetchone()

        if client is None:
            result = engine.execute("""
                INSERT INTO clients (company_id, name, created_by)
                VALUES (%s, %s, %s)
            """, (COMPANY_ID, client_name, CREATED_BY))
            client_id = result.lastrowid
        else:
            client_id = client[0]

        # insert project
        result = engine.execute("""
            INSERT INTO projects
            (company_id, project_type_id, client_id, pic_id, number, name, work_basis,
             request_number_date, project_value, start_date, end_date)
            VALUES (%s, 1, %s, 1, %s, %s, %s, %s, %s, %s, %s)
        """, (
            COMPANY_ID,
            client_id,
            p["number"],
            p["name"],
            p["work_basis"],
            p["request_number_date"],
            p["project_value"],
            p["start_date"],
            p["end_date"]
        ))

        project_id = result.lastrowid

        print(f"[PROJECT] Inserted: {p['name']} (ID {project_id})")


### ========== MIGRATE INVOICE ==========
if "Invoice" in ORDER:
    df = pd.read_excel(excel_path, sheet_name="Invoice")
    inv_data = parse_invoice_sheet(df)

    for inv in inv_data:
        # cari project by project number
        project = engine.execute(
            "SELECT id, client_id FROM projects WHERE number=%s LIMIT 1",
            (inv["project_number"],)
        ).fetchone()

        if project is None:
            print(f"[WARNING] Project not found for invoice {inv['invoice_number']}")
            continue

        project_id = project[0]
        client_id = project[1]

        result = engine.execute("""
            INSERT INTO invoices
            (company_id, client_id, project_id, invoice_number, date, total, amount_paid, status)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
        """, (
            COMPANY_ID,
            client_id,
            project_id,
            inv["invoice_number"],
            inv["date"],
            inv["total_after_ppn"],
            inv["paid_value"],
            inv["status"]
        ))

        invoice_id = result.lastrowid

        # insert ke document_line_items
        engine.execute("""
            INSERT INTO document_line_items
            (company_id, documentable_type, documentable_id, description, unit_price, tax_total, total)
            VALUES (%s, 'invoice', %s, %s, %s, %s, %s)
        """, (
            COMPANY_ID,
            invoice_id,
            inv["item_description"],
            inv["dpp"],
            inv["ppn"],
            inv["total_after_ppn"]
        ))

        print(f"[INVOICE] Inserted: {inv['invoice_number']} (ID {invoice_id})")

print("Migration completed.")
