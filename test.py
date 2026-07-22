import pandas as pd
import json
import mysql.connector
import duckdb
from decimal import Decimal
from datetime import date, datetime


def json_default(value):
    if isinstance(value, Decimal):
        return float(value)
    if isinstance(value, (datetime, date)):
        return value.isoformat()
    if hasattr(value, "item"):
        return value.item()
    if pd.isna(value):
        return None
    raise TypeError(f"Object of type {type(value).__name__} is not JSON serializable")

def query(sql_query, params=None):
    db = duckdb.connect('/home/azim/projects/Datavue/storage/app/company/1/chats/49/extracted_data/data.duckdb')

    try:
        result = db.execute(sql_query, params or ()).fetchall()
        return result
    finally:
        db.close()

def main():
    rows = query("SELECT priceEach, quantityOrdered FROM orderdetails")
    if not rows:
        result = {"series": [{"name": "Name", "data": []}], "categories": []}
        print(json.dumps(result, ensure_ascii=False, default=json_default))
        return

    df = pd.DataFrame(rows, columns=['priceEach', 'quantityOrdered'])
    qty = pd.to_numeric(df['quantityOrdered'], errors='coerce')
    price_vals = df['priceEach']

    data = qty.fillna(0).astype('int64').tolist()
    categories = [str(p) for p in price_vals.tolist()]

    result = {"series": [{"name": "Name", "data": data}], "categories": categories}
    print(json.dumps(result, ensure_ascii=False, default=json_default))

if __name__ == "__main__":
    main()
