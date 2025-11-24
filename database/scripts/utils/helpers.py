import pandas as pd

def parse_date(val):
    if pd.isna(val): 
        return None
    try:
        return pd.to_datetime(val).date()
    except:
        return None

def parse_number(val):
    if pd.isna(val):
        return 0
    try:
        return int(str(val).replace(",", "").replace(" ", ""))
    except:
        return 0
