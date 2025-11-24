from utils.helpers import parse_date, parse_number
import pandas as pd

def parse_project_sheet(df):
    projects = []

    for _, row in df.iterrows():
        projects.append({
            "name": row["Nama Projek"],
            "request_number_date": parse_date(row["Tanggal Permintaan"]),
            "number_prefix": row["Nomor Prefix"],
            "number_registration": row["Nomor Registrasi"],
            "number": row["Nomor Projek"],
            "work_basis": row["Dasar Pekerjaan"],
            "project_value": parse_number(row["Nilai Projek"]),
            "start_date": parse_date(row["Tanggal Mulai"]),
            "end_date": parse_date(row["Tanggal Selesai"]),
            "client_name": row["Client"],
            "pic_client": row["PIC Client"],
            "pic_internal": row["PIC Internal"],
            "notes": row["Keterangan"],
        })

    return projects

def parse_invoice_sheet(df):
    invoices = []

    for _, row in df.iterrows():
        invoices.append({
            "invoice_number": row["Nomor Invoice"],
            "date": parse_date(row["Tanggal"]),
            "project_number": row["Nomor Projek"],
            "faktur_number": row["Nomor Faktur"],
            "item_description": row["Offering Item"],
            "dpp": parse_number(row["DPP"]),
            "profit": parse_number(row["Profit"]),
            "ppn": parse_number(row["PPN"]),
            "total_after_ppn": parse_number(row["Total Setelah PPN"]),
            "status": row["Status Bayar"],
            "paid_value": parse_number(row["Nilai Invoice yang dibayar"]),
        })

    return invoices
