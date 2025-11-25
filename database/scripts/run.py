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

    # Display companies with premium UI
    for comp in companies:
        print(Fore.LIGHTWHITE_EX + f"[{comp.id}] {comp.name}")

# Ask user to select a company
company_choice = input(
    Fore.CYAN + "\nSelect company ID to use for this import: " + Style.RESET_ALL
).strip()

# Validate input
valid_ids = [str(c.id) for c in companies]

if company_choice not in valid_ids:
    print(Fore.RED + "✖ Invalid company ID. Stopping." + Style.RESET_ALL)
    exit()

# Override COMPANY_ID from .env
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
# 3. SORT SHEETS (Project-* first, then others)
# ============================================================
project_sheets = [s for s in available_sheets if s.lower().startswith("project")]
other_sheets   = [s for s in available_sheets if not s.lower().startswith("project")]

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
    # PROJECT-* SHEETS
    # ============================================================
    if parser_type == "project":

        proj_data = parse_project_sheet(df)
        project_type_name = sheet.split("Project-")[1].strip()

        with engine.connect() as conn:
            # LOOKUP PROJECT TYPE
            project_type = conn.execute(
                text("""
                    SELECT id FROM project_types
                    WHERE name = :name AND company_id = :cid
                    LIMIT 1
                """),
                {"name": project_type_name, "cid": COMPANY_ID}
            ).fetchone()

            # STOP IF PROJECT TYPE IS MISSING
            if project_type is None:
                print(Fore.RED + "✖ ERROR: Project type '" + project_type_name + "' does not exist in database.")
                print(Fore.RED + "✖ Please create this project type first in the 'project_types' table.")
                print(Fore.RED + "✖ Stopping execution..." + Style.RESET_ALL)
                exit(1)

            # Extract ID when exists
            project_type_id = project_type[0]
            section(f"Project Type: {project_type_name}")

            # PROCESS EACH PROJECT ROW
            for p in proj_data:
                # ----------------------------------------------------------
                # CLIENT LOOKUP
                # ----------------------------------------------------------
                client_name = clean_value(p["client_name"])

                client = conn.execute(
                    text("""
                        SELECT id FROM clients
                        WHERE name = :name AND company_id = :cid LIMIT 1
                    """),
                    {"name": client_name, "cid": COMPANY_ID}
                ).fetchone()

                # INSERT CLIENT IF NOT EXISTS
                if client is None and client_name is not None:
                    result = conn.execute(
                        text("""
                            INSERT INTO clients (company_id, name)
                            VALUES (:cid, :name)
                        """),
                        {"cid": COMPANY_ID, "name": client_name}
                    )
                    conn.commit()
                    client_id = result.lastrowid
                    success(f"Created new client: {client_name}")
                else:
                    if client is not None:
                        client_id = client[0]
                    else:
                        continue

                # ----------------------------------------------------------
                # INSERT PROJECT
                # ----------------------------------------------------------
                result = conn.execute(
                    text("""
                        INSERT INTO projects
                        (company_id, project_type_id, client_id, pic_id, number, name, work_basis,
                         request_number_date, project_value, start_date, end_date)
                        VALUES (:cid, :ptid, :clid, 1, :num, :name, :basis,
                                :req, :value, :start, :end)
                    """),
                    {
                        "cid": COMPANY_ID,
                        "ptid": project_type_id,
                        "clid": client_id,
                        "num": clean_value(p["number"]),
                        "name": clean_value(p["name"]),
                        "basis": clean_value(p["work_basis"]),
                        "req": clean_value(p["request_number_date"]),
                        "value": clean_value(p["project_value"]) * 100,
                        "start": clean_value(p["start_date"]),
                        "end": clean_value(p["end_date"])
                    }
                )

                conn.commit()
                project_id = result.lastrowid
                success(f"Inserted Project: {p['name']} (ID {project_id})")

    # ============================================================
    # INVOICE SHEET
    # ============================================================
    elif parser_type == "invoice":

        inv_data = parse_invoice_sheet(df)

        with engine.connect() as conn:
            for inv in inv_data:
                print(inv)
                project_number = clean_value(inv["project_number"])
                if project_number is None:
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

                project_id = project[0]
                client_id = project[1]

                # INSERT INVOICE
                result = conn.execute(
                    text("""
                        INSERT INTO invoices
                        (company_id, client_id, project_id, invoice_number,
                         date, total, amount_paid, status, currency_code)
                        VALUES (:cid, :clid, :pid, :inv, :date, :total, :paid, :status, :curency)
                    """),
                    {
                        "cid": COMPANY_ID,
                        "clid": client_id,
                        "pid": project_id,
                        "inv": clean_value(inv["invoice_number"]),
                        "date": clean_value(inv["date"]),
                        "total": clean_value(inv["total_after_ppn"]),
                        "paid": clean_value(inv["paid_value"]),
                        "status": "paid",
                        "curency": "IDR",
                    }
                )

                conn.commit()
                invoice_id = result.lastrowid

                # INSERT LINE ITEM
                conn.execute(
                    text("""
                        INSERT INTO document_line_items
                        (company_id, offering_id, documentable_type, documentable_id,
                         description, unit_price, tax_total)
                        VALUES (:cid, :oid, 'App\\Models\\Accounting\\Invoice', :iid, :desc, :price, :tax)
                    """),
                    {
                        "cid": COMPANY_ID,
                        "oid": offering_id,
                        "iid": invoice_id,
                        "desc": clean_value(inv["item_description"]),
                        "price": clean_value(inv["dpp"]),
                        "tax": clean_value(inv["ppn"]),
                    }
                )
                conn.commit()

                success(f"Inserted Invoice: {inv['invoice_number']} (ID {invoice_id})")

print(Fore.GREEN + "\n✔ Migration completed successfully!" + Style.RESET_ALL)
