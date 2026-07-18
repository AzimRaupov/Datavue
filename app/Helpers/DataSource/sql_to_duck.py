import duckdb
import re
import argparse
from pathlib import Path


def split_statements(sql_text):
    """Разбивает SQL на инструкции, учитывая кавычки."""
    statements = []
    current = []
    in_quote = False
    escape_next = False

    for char in sql_text:
        if escape_next:
            current.append(char)
            escape_next = False
            continue

        if char == '\\':
            current.append(char)
            escape_next = True
            continue

        if char == "'":
            in_quote = not in_quote

        if char == ';' and not in_quote:
            stmt = ''.join(current).strip()
            if stmt and not stmt.startswith('--'):
                statements.append(stmt)
            current = []
            continue

        current.append(char)

    stmt = ''.join(current).strip()
    if stmt and not stmt.startswith('--'):
        statements.append(stmt)

    return statements


def import_sql_to_duckdb(sql_file, db_path="my_database.duckdb"):
    # Удаляем старую БД перед импортом
    db_file = Path(db_path)

    if db_file.exists() and db_path != ':memory:':
        db_file.unlink()

    for wal in Path('.').glob(f"{db_file.stem}.duckdb.wal*"):
        wal.unlink()

    conn = duckdb.connect(db_path)

    with open(sql_file, 'r', encoding='utf-8') as f:
        sql_text = f.read()

    statements = split_statements(sql_text)

    success = 0
    failed = 0

    for i, stmt in enumerate(statements, 1):
        if not stmt or stmt.upper().startswith('--'):
            continue

        # Пропускаем MySQL-специфичные инструкции
        upper = stmt.upper().strip()

        if upper.startswith('SET ') and 'NAMES' in upper:
            continue

        if upper.startswith('/*!'):
            continue

        if 'SET TIME_ZONE' in upper or 'SET time_zone' in stmt:
            continue

        try:
            conn.execute(stmt)
            success += 1
        except Exception as e:
            failed += 1
            print(f"  [{i}/{len(statements)}] ОШИБКА: {e}")
            print(f"    SQL: {stmt[:300]}")

    if failed == 0:
        print("ok")
    else:
        print(f"\nИмпортировано: {success}, ошибок: {failed}")

    conn.close()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(
        description="Импорт SQL-файла в DuckDB"
    )

    parser.add_argument(
        "--sql",
        required=True,
        help="Путь к SQL-файлу"
    )

    parser.add_argument(
        "--path",
        required=True,
        help="Путь к DuckDB-файлу"
    )

    args = parser.parse_args()

    import_sql_to_duckdb(
        sql_file=args.sql,
        db_path=args.path
    )
