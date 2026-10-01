<?php

namespace App\Helpers\DataSource;

use App\Models\DataSource;
use RuntimeException;

class CodeTemplater
{
    public DataSource $dataSource;

    public ?string $token;

    public function __construct(
        int $dataSourceId,
        ?string $token = null
    ) {
        $this->dataSource = DataSource::with([
            'type',
            'extracted',
        ])->findOrFail($dataSourceId);

        $this->token = $token;
    }

    private function pyString(?string $value): string
    {
        $value = (string) $value;
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);

        return "'" . $escaped . "'";
    }

    public function getLibraries(): string
    {

        $driverImport = $this->driverImport();

        return <<<PYTHON
import pandas as pd
import json
{$driverImport}
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
PYTHON;
    }

    private function driverImport(): string
    {
        return match ($this->dataSource->type->name) {
            'mysql' => 'import mysql.connector',
            'duckdb' => 'import duckdb',
            'postgres' => 'import psycopg2',
            'sqlite' => 'import sqlite3',
            default => throw new RuntimeException(
                "CodeTemplater: неподдерживаемый тип источника данных '{$this->dataSource->type->name}'"
            ),
        };
    }

    public function getQueryTemplate($isPrivate=true): string
    {
        $typeName = $this->dataSource->type->name;

        $template = match ($typeName) {
            'mysql' => $this->getMysqlQueryTemplate($isPrivate),
            'duckdb' => $this->getDuckDbQueryTemplate(),
            'postgres' => $this->getPostgresQueryTemplate(),
            'sqlite' => $this->getSqliteQueryTemplate(),
            default => throw new RuntimeException(
                "CodeTemplater: неподдерживаемый тип источника данных '{$typeName}'"
            ),
        };

        return str_replace(["\r\n", "\r"], "\n", $template) . "\n";
    }

    private function getPostgresQueryTemplate(): string
    {
        $host = $this->pyString($this->dataSource->host);
        $user = $this->pyString($this->dataSource->username);
        $password = $this->pyString($this->dataSource->password);
        $database = $this->pyString($this->dataSource->database);
        $port = (int) ($this->dataSource->port ?: 5432);

        return <<<PYTHON
def query(sql_query, params=None):
    db = psycopg2.connect(
        host={$host},
        user={$user},
        password={$password},
        dbname={$database},
        port={$port}
    )
    try:
        cursor = db.cursor()
        cursor.execute(sql_query, params or None)
        result = cursor.fetchall()
        cursor.close()
        return result
    finally:
        db.close()
PYTHON;
    }

    private function getSqliteQueryTemplate(): string
    {
        $path = $this->dataSource->extracted->data_path
            ?? $this->dataSource->path
            ?? $this->dataSource->database;

        $pathDb = $this->pyString($path);

        return <<<PYTHON
def query(sql_query, params=None):
    db = sqlite3.connect('file:' + {$pathDb} + '?mode=ro', uri=True)

    try:
        cursor = db.cursor()
        cursor.execute(sql_query, params or ())
        result = cursor.fetchall()
        cursor.close()
        return result
    finally:
        db.close()
PYTHON;
    }

    private function getMysqlQueryTemplate($isPrivate): string
    {

        if ($isPrivate) {
            return <<<PYTHON
def query(sql_query, params=None):
    db = mysql.connector.connect(
        host='127.0.0.1',
        user='root',
        password='',
        database='testdb',
        port=3306
    )
    cursor = db.cursor()
    cursor.execute(sql_query, params or ())
    result = cursor.fetchall()
    cursor.close()
    db.close()
    return result
PYTHON;
        }

        $host = $this->pyString($this->dataSource->host);
        $user = $this->pyString($this->dataSource->username);
        $password = $this->pyString($this->dataSource->password);
        $database = $this->pyString($this->dataSource->database);
        $port = (int) ($this->dataSource->port ?: 3306);

        return <<<PYTHON
def query(sql_query, params=None):
    db = mysql.connector.connect(
        host={$host},
        user={$user},
        password={$password},
        database={$database},
        port={$port}
    )
    cursor = db.cursor()
    cursor.execute(sql_query, params or ())
    result = cursor.fetchall()
    cursor.close()
    db.close()
    return result
PYTHON;
    }

    private function getDuckDbQueryTemplate(): string
    {
        $pathDb = $this->pyString($this->dataSource->extracted->data_path ?? null);

        return <<<PYTHON
def query(sql_query, params=None):
    db = duckdb.connect({$pathDb})

    try:
        result = db.execute(sql_query, params or ()).fetchall()
        return result
    finally:
        db.close()
PYTHON;
    }

    public function getQueryDataFrameTemplate(): string
    {
        $typeName = $this->dataSource->type->name;

        $template = match ($typeName) {
            'mysql' => $this->getMysqlDataFrameTemplate(),
            'duckdb' => $this->getDuckDbDataFrameTemplate(),
            'postgres' => $this->getPostgresDataFrameTemplate(),
            'sqlite' => $this->getSqliteDataFrameTemplate(),
            default => throw new RuntimeException(
                "CodeTemplater: неподдерживаемый тип источника данных '{$typeName}'"
            ),
        };

        return str_replace(["\r\n", "\r"], "\n", $template)."\n";
    }

    private function getMysqlDataFrameTemplate(): string
    {
        $host = $this->pyString($this->dataSource->host);
        $user = $this->pyString($this->dataSource->username);
        $password = $this->pyString($this->dataSource->password);
        $database = $this->pyString($this->dataSource->database);
        $port = (int) ($this->dataSource->port ?: 3306);

        return <<<PYTHON
def query_df(sql_query, params=None):
    db = mysql.connector.connect(
        host={$host},
        user={$user},
        password={$password},
        database={$database},
        port={$port}
    )
    try:
        cursor = db.cursor()
        cursor.execute(sql_query, params or ())
        rows = cursor.fetchall()
        columns = [column[0] for column in (cursor.description or [])]
        cursor.close()
        return pd.DataFrame(rows, columns=columns or None)
    finally:
        db.close()
PYTHON;
    }

    private function getPostgresDataFrameTemplate(): string
    {
        $host = $this->pyString($this->dataSource->host);
        $user = $this->pyString($this->dataSource->username);
        $password = $this->pyString($this->dataSource->password);
        $database = $this->pyString($this->dataSource->database);
        $port = (int) ($this->dataSource->port ?: 5432);

        return <<<PYTHON
def query_df(sql_query, params=None):
    db = psycopg2.connect(
        host={$host},
        user={$user},
        password={$password},
        dbname={$database},
        port={$port}
    )
    try:
        cursor = db.cursor()
        cursor.execute(sql_query, params or None)
        rows = cursor.fetchall()
        columns = [column[0] for column in (cursor.description or [])]
        cursor.close()
        return pd.DataFrame(rows, columns=columns or None)
    finally:
        db.close()
PYTHON;
    }

    private function getSqliteDataFrameTemplate(): string
    {
        $path = $this->dataSource->extracted->data_path
            ?? $this->dataSource->path
            ?? $this->dataSource->database;

        $pathDb = $this->pyString($path);

        return <<<PYTHON
def query_df(sql_query, params=None):
    db = sqlite3.connect('file:' + {$pathDb} + '?mode=ro', uri=True)

    try:
        cursor = db.cursor()
        cursor.execute(sql_query, params or ())
        rows = cursor.fetchall()
        columns = [column[0] for column in (cursor.description or [])]
        cursor.close()
        return pd.DataFrame(rows, columns=columns or None)
    finally:
        db.close()
PYTHON;
    }

    private function getDuckDbDataFrameTemplate(): string
    {
        $pathDb = $this->pyString($this->dataSource->extracted->data_path ?? null);

        return <<<PYTHON
def query_df(sql_query, params=None):
    db = duckdb.connect({$pathDb})

    try:
        return db.execute(sql_query, params or ()).fetchdf()
    finally:
        db.close()
PYTHON;
    }

    private function getPlaceholderMain(): string
    {
        return <<<PYTHON
def main():
    result = []
    print(json.dumps(result, ensure_ascii=False, default=json_default))
PYTHON;
    }

    public function getFooter(): string
    {
        return <<<PYTHON
if __name__ == "__main__":
    main()
PYTHON;
    }

    public function generateFullScript(): string
    {
        return implode("\n\n", [
                rtrim($this->getLibraries()),
                rtrim($this->getQueryTemplate()),
                $this->getPlaceholderMain(),
                $this->getFooter(),
            ]) . "\n";
    }

    public function assembleScript(string $mainBody): string
    {
        $mainBody = trim($mainBody);
        $mainBody = preg_replace('/^```(?:python)?\s*/i', '', $mainBody);
        $mainBody = preg_replace('/\s*```$/', '', $mainBody);
        $mainBody = trim($mainBody);

        if (!preg_match('/^\s*def\s+main\s*\(\s*\)\s*:/', $mainBody)) {
            if (preg_match('/def\s+main\s*\(\s*\)\s*:.*/s', $mainBody, $m)) {
                $mainBody = $m[0];
            }
        }

        return implode("\n\n", [
                rtrim($this->getLibraries()),
                rtrim($this->getQueryTemplate()),
                $mainBody,
                $this->getFooter(),
            ]) . "\n";
    }
}
