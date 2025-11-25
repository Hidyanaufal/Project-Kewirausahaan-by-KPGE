import pandas as pd
import math

def parse_date(val):
    """
    Parse a date-like value and return a string (YYYY-MM-DD).
    If invalid or empty, return None.
    """
    if pd.isna(val):
        return None
    try:
        dt = pd.to_datetime(val, dayfirst=False, errors='coerce')
        if dt is None or pd.isna(dt):
            return None
        return dt.strftime("%Y-%m-%d")  # return as string
    except:
        return None


def parse_number(val):
    """
    Parse numeric fields, cleaning commas/spaces.
    Return integer. If invalid, return 0.
    """
    if pd.isna(val):
        return 0
    try:
        cleaned = str(val).replace(",", "").replace(" ", "")
        return int(float(cleaned))  # handle numbers like "1000.0"
    except:
        return 0


def clean_value(val):
    if val is None:
        return None
    
    # Pandas NaN / numpy NaN
    if isinstance(val, float) and math.isnan(val):
        return None
    
    # pandas NA
    if pd.isna(val):
        return None
    
    return val