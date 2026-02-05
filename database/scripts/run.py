import os
import pandas as pd
from dotenv import load_dotenv
from sqlalchemy import text
from utils.db import engine
from utils.parser import parse_project_sheet, parse_invoice_sheet
from utils.helpers import clean_value

# Fancy terminal UI
from colorama import init, Fore, Style
init(autoreset=True)

load_dotenv()

CREATED_BY = int(os.getenv("CREATED_BY"))
EXCEL_DIR = "excel"

# ============================================================
# PREMIUM UI FUNCTIONS
# ============================================================
def print_header():
    print(Fore.CYAN + "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")
    print(Fore.CYAN + "  EXCEL IMPORTER v1.0 — ImpactSync System")
    print(Fore.CYAN + "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" + Style.RESET_ALL)


def success(msg):
    print(Fore.GREEN + "✔ " + msg + Style.RESET_ALL)


def warn(msg):
    print(Fore.YELLOW + "⚠ " + msg + Style.RESET_ALL)


def info(msg):
    print(Fore.CYAN + "ℹ " + msg + Style.RESET_ALL)


def section(title):
    print(Fore.MAGENTA + f"\n🚀 Start processing: {title}")
    print(Fore.MAGENTA + "-----------------------------------------" + Style.RESET_ALL)


# ============================================================
# 1. FILE SELECTION
# ============================================================
print_header()

files = [f for f in os.listdir(EXCEL_DIR) if f.lower().endswith(".xlsx")]

if not files:
    warn("No .xlsx files found in the 'excel/' directory.")
    exit()

print(Fore.WHITE + "Available Excel Files:\n")

for i, f in enumerate(files, start=1):
    print(Fore.LIGHTWHITE_EX + f"[{i}] " + f)

choice = input(Fore.CYAN + "\nSelect Excel file to process: " + Style.RESET_ALL).strip()

if not choice.isdigit() or int(choice) < 1 or int(choice) > len(files):
    warn("Invalid selection. Stopping script.")
    exit()

selected_file = files[int(choice) - 1]
excel_path = os.path.join(EXCEL_DIR, selected_file)

success(f"Selected file: {selected_file}\n")

# ============================================================
# 0. SELECT COMPANY FIRST (NEW FLOW)
# ============================================================
print(Fore.WHITE + "\nAvailable Companies:\n")

with engine.connect() as conn:
    companies = conn.execute(text("SELECT id, name FROM companies ORDER BY id")).fetchall()

    if not companies:
        print(Fore.RED + "✖ ERROR: No companies found in database.")
        exit(1)

    for comp in companies:
        print(Fore.LIGHTWHITE_EX + f"[{comp.id}] {comp.name}")

company_choice = input(
    Fore.CYAN + "\nSelect company ID to use for this import: " + Style.RESET_ALL
).strip()

valid_ids = [str(c.id) for c in companies]

if company_choice not in valid_ids:
    print(Fore.RED + "✖ Invalid company ID. Stopping." + Style.RESET_ALL)
    exit()

COMPANY_ID = int(company_choice)

success(f"Using Company ID: {COMPANY_ID}\n")

# ============================================================
# 2. DETECT SHEETS
# ============================================================
xls = pd.ExcelFile(excel_path)
available_sheets = xls.sheet_names[:-1]

info("Detecting sheets...")
for s in available_sheets:
    print(Fore.LIGHTWHITE_EX + f" - {s}")

# ============================================================
# 3. SORT SHEETS
# ============================================================
project_sheets = [s for s in available_sheets if s.lower().startswith("project")]
other_sheets = [s for s in available_sheets if not s.lower().startswith("project")]

ORDER = project_sheets + other_sheets

# ============================================================
# OFFERING DEFAULT
# ============================================================
section("Checking default offering")

with engine.connect() as conn:
    offering = conn.execute(
        text("""
            SELECT id 
            FROM offerings 
            WHERE name = :name AND company_id = :cid
            LIMIT 1
        """),
        {"name": "Pekerjaan", "cid": COMPANY_ID}
    ).fetchone()

    if offering is None:
        warn(f"Default offering 'Pekerjaan' does not exist for company ID {COMPANY_ID}")
        raise SystemExit(
            f"Default offering is missing for this company (company_id={COMPANY_ID})."
        )

    offering_id = offering[0]

success(f"Default offering found: ID {offering_id}")

# ============================================================
# 4. EXECUTION LOOP
# ============================================================
for sheet in ORDER:

    # Determine parser
    if sheet.lower().startswith("project"):
        parser_type = "project"
    elif sheet.lower() == "invoice":
        parser_type = "invoice"
    else:
        warn(f"Skipping sheet '{sheet}' — no parser defined.")
        continue

    section(sheet)
    df = pd.read_excel(excel_path, sheet_name=sheet)

    # ============================================================
    # PROJECT SHEET
    # ============================================================
    if parser_type == "project":

        proj_data = parse_project_sheet(df)
        project_type_name = sheet.split("Project-")[1].strip()

        with engine.connect() as conn:
            project_type = conn.execute(
                text("""
                    SELECT id FROM project_types
                    WHERE name = :name AND company_id = :cid
                    LIMIT 1
                """),
                {"name": project_type_name, "cid": COMPANY_ID}
            ).fetchone()

            if project_type is None:
                print(Fore.RED + f"✖ ERROR: Project type '{project_type_name}' not found.")
                exit(1)

            project_type_id = project_type[0]
            section(f"Project Type: {project_type_name}")

            last_client_id = None
            last_client_name = None

            for p in proj_data:

                project_number = clean_value(p["number"])
                project_name = clean_value(p["name"])
                client_name = clean_value(p["client_name"])

                # -----------------------
                # CEK DUPLIKAT PROJECT
                # -----------------------
                if not project_number:
                    warn(f"Project number empty for project '{project_name}'. Skipped.")
                    continue

                existing_project = conn.execute(
                    text("""
                        SELECT id FROM projects
                        WHERE number = :num AND company_id = :cid
                        LIMIT 1
                    """),
                    {"num": project_number, "cid": COMPANY_ID}
                ).fetchone()

                if existing_project:
                    print(f"⚠ Project {project_name} (Number {project_number}) sudah ada (ID {existing_project[0]}), skip insert")
                    # Cek apakah nama project di database sama dengan nama project di excel
                    project_name_db = conn.execute(
                        text("SELECT name FROM projects WHERE id = :id"),
                        {"id": existing_project[0]}
                    ).fetchone()
                    # Jika nama project di database berbeda maka samakan dengan nama project di excel
                    if project_name_db[0] != project_name:
                        result = conn.execute(
                            text("UPDATE projects SET name = :name WHERE id = :id"),
                            {"name": project_name, "id": existing_project[0]}
                        )
                        conn.commit()
                        success(f"Updated project name: {project_name}")
                    continue  # skip jika sudah ada

                # -----------------------
                # CEK / INSERT CLIENT
                # -----------------------
                if not client_name:
                    if last_client_id is None:
                        warn(f"Client empty for project '{project_name}'. Skipped.")
                        continue
                    client_id = last_client_id
                    info(f"Using previous client: {last_client_name}")
                else:
                    client = conn.execute(
                        text("""
                            SELECT id FROM clients
                            WHERE name = :name AND company_id = :cid LIMIT 1
                        """),
                        {"name": client_name, "cid": COMPANY_ID}
                    ).fetchone()

                    if client is None:
                        result = conn.execute(
                            text("""
                                INSERT INTO clients (company_id, name, currency_code)
                                VALUES (:cid, :name, 'IDR')
                            """),
                            {"cid": COMPANY_ID, "name": client_name}
                        )
                        conn.commit()
                        client_id = result.lastrowid
                        success(f"Created new client: {client_name}")
                    else:
                        client_id = client[0]

                    last_client_id = client_id
                    last_client_name = client_name

                if not project_name:
                    continue

                # -----------------------
                # INSERT PROJECT
                # -----------------------
                result = conn.execute(
                    text("""
                        INSERT INTO projects
                        (company_id, project_type_id, client_id, pic_id, number, name, work_basis,
                        request_number_date, project_value, start_date, end_date, is_imported)
                        VALUES (:cid, :ptid, :clid, 1, :num, :name, :basis,
                                :req, :value, :start, :end, 1)
                    """),
                    {
                        "cid": COMPANY_ID,
                        "ptid": project_type_id,
                        "clid": client_id,
                        "num": project_number,
                        "name": project_name,
                        "basis": clean_value(p["work_basis"]),
                        "req": clean_value(p["request_number_date"]),
                        "value": clean_value(p["project_value"]) * 100,
                        "start": clean_value(p["start_date"]),
                        "end": clean_value(p["end_date"])
                    }
                )

                conn.commit()
                project_id = result.lastrowid
                success(f"Inserted Project: {project_name} (ID {project_id})")

    # ============================================================
    # INVOICE SHEET
    # ============================================================
    if parser_type == "invoice":

        inv_data = parse_invoice_sheet(df)

        with engine.connect() as conn:
            for inv in inv_data:
                
                project_number = clean_value(inv["project_number"])
                if not project_number or project_number in ["0", 0]:
                    continue

                project = conn.execute(
                    text("""
                        SELECT id, client_id
                        FROM projects
                        WHERE number = :num
                        LIMIT 1
                    """),
                    {"num": project_number}
                ).fetchone()

                if project is None:
                    warn(f"Project not found for invoice {inv['invoice_number']}")
                    continue

                project_id, client_id = project
                invoice_number = clean_value(inv["invoice_number"])

                # Ambil nilai (untuk insert dan update)
                dpp = clean_value(inv["dpp"]) or 0
                profit = clean_value(inv.get("profit", 0)) or 0
                ppn = clean_value(inv["ppn"]) or 0

                if dpp > 0 and profit > 0:
                    profit_percentage = (profit / dpp) * 100.0
                    profit_rate = int(round(profit_percentage * 10000))   # 1% -> 10000 ; 12% -> 120000
                else:
                    profit_percentage = 0.0
                    profit_rate = 0

                profit_total = profit
                total = dpp + profit_total + ppn

                # Status & amount_paid dari Excel: PAID = lunas, else pakai paid_value jika angka > 0
                is_paid_status = str(inv.get("status", "")).strip().upper() == "PAID"
                paid_value = inv.get("paid_value", 0) or 0
                if is_paid_status:
                    invoice_status = "paid"
                    amount_paid_cents = int(round(total * 100))
                elif paid_value > 0:
                    invoice_status = "partial"
                    amount_paid_cents = int(round(paid_value * 100))
                else:
                    invoice_status = "sent"
                    amount_paid_cents = 0

                # -----------------------
                # CEK DUPLIKAT INVOICE (upsert by invoice_number + company_id)
                # -----------------------
                existing = conn.execute(
                    text("SELECT id FROM invoices WHERE invoice_number = :inv AND company_id = :cid"),
                    {"inv": invoice_number, "cid": COMPANY_ID}
                ).fetchone()

                if existing:
                    invoice_id = existing[0]
                    conn.execute(
                        text("""
                            UPDATE invoices SET
                                status = :status,
                                amount_paid = :amount_paid,
                                date = :date,
                                subtotal = :subtotal,
                                profit_rate = :profit_rate,
                                profit_total = :profit_total,
                                tax_total = :tax_total,
                                total = :total,
                                actual_total = :actual_total
                            WHERE id = :id
                        """),
                        {
                            "id": invoice_id,
                            "status": invoice_status,
                            "amount_paid": amount_paid_cents,
                            "date": clean_value(inv["date"]),
                            "subtotal": int(round(dpp * 100)),
                            "profit_rate": profit_rate,
                            "profit_total": int(round(profit_total * 100)),
                            "tax_total": int(round(ppn * 100)),
                            "total": int(round(total * 100)),
                            "actual_total": int(round(total * 100)),
                        }
                    )
                    conn.commit()
                    success(f"Updated Invoice: {invoice_number} (ID {invoice_id})")
                else:
                    # -----------------------
                    # INSERT KE DATABASE
                    # -----------------------
                    result = conn.execute(
                        text("""
                            INSERT INTO invoices
                            (company_id, client_id, project_id, invoice_number,
                            date, subtotal, profit_computation, profit_rate, profit_total,
                            tax_total, total, actual_total, amount_paid, status, currency_code, is_imported)
                            VALUES (:cid, :clid, :pid, :inv, :date,
                                    :subtotal, 'percentage', :profit_rate, :profit_total,
                                    :ppn, :total, :actual_total, :amount_paid, :status, :cur, 1)
                        """),
                        {
                            "cid": COMPANY_ID,
                            "clid": client_id,
                            "pid": project_id,
                            "inv": invoice_number,
                            "date": clean_value(inv["date"]),
                            "subtotal": int(round(dpp * 100)),
                            "profit_rate": profit_rate,
                            "profit_total": int(round(profit_total * 100)),
                            "ppn": int(round(ppn * 100)),
                            "total": int(round(total * 100)),
                            "actual_total": int(round(total * 100)),
                            "amount_paid": amount_paid_cents,
                            "status": invoice_status,
                            "cur": "IDR",
                        }
                    )
                    conn.commit()
                    invoice_id = result.lastrowid

                    # INSERT LINE ITEMS
                    conn.execute(
                        text("""
                            INSERT INTO document_line_items
                            (company_id, offering_id, documentable_type, documentable_id,
                            description, unit_price, tax_total)
                            VALUES (:cid, :oid, 'App\\\\Models\\\\Accounting\\\\Invoice', :iid, :desc, :price, :tax)
                        """),
                        {
                            "cid": COMPANY_ID,
                            "oid": offering_id,
                            "iid": invoice_id,
                            "desc": clean_value(inv["item_description"]),
                            "price": int(round(dpp * 100)),
                            "tax": int(round(ppn * 100)),
                        }
                    )
                    conn.commit()
                    success(f"Inserted Invoice: {invoice_number} (ID {invoice_id})")