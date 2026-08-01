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

    /**
     * Экранирует строку для безопасной подстановки в одинарные кавычки Python.
     */
    private function pyString(?string $value): string
    {
        $value = (string) $value;
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);

        return "'" . $escaped . "'";
    }

    /**
     * Генерирует импорты и вспомогательные функции Python-библиотек.
     * json_default нужен, чтобы json.dumps умел сериализовать Decimal/date/datetime,
     * а также numpy-скаляры, которые часто прилетают из pandas (.sum(), .mean() и т.д.).
     */
    public function getLibraries(): string
    {
        return <<<PYTHON
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
PYTHON;
    }

    /**
     * Генерирует функцию выполнения SQL-запроса на основе реального источника данных
     * (реальные хост/порт/база/юзер/пароль для mysql, реальный путь для duckdb).
     * Никаких плейсхолдеров и тестовых данных здесь больше нет.
     */
    public function getQueryTemplate($isPrivate=true): string
    {
        $typeName = $this->dataSource->type->name;

        if ($typeName === 'mysql') {
            $template = $this->getMysqlQueryTemplate($isPrivate);
        } elseif ($typeName === 'duckdb') {
            $template = $this->getDuckDbQueryTemplate($isPrivate);
        } else {
            throw new RuntimeException("CodeTemplater: неподдерживаемый тип источника данных '{$typeName}'");
        }

        return str_replace(["\r\n", "\r"], "\n", $template) . "\n";
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

    /**
     * Плейсхолдер main() — используется только как пример структуры внутри
     * generateFullScript(), который передаётся модели как контекст. Никогда
     * не должен попадать в реально сохраняемый файл.
     */
    private function getPlaceholderMain(): string
    {
        return <<<PYTHON
def main():
    result = []
    print(json.dumps(result, ensure_ascii=False, default=json_default))
PYTHON;
    }

    /**
     * Финальный вызов main() — общий "футер" для всех сгенерированных скриптов.
     */
    public function getFooter(): string
    {
        return <<<PYTHON
if __name__ == "__main__":
    main()
PYTHON;
    }

    /**
     * Полный скрипт-пример (с плейсхолдер-main). Используется ТОЛЬКО как контекст,
     * который показывается модели в промпте ("вот что уже есть в файле").
     * Для реального сохраняемого файла используйте assembleScript().
     */
    public function generateFullScript(): string
    {
        return implode("\n\n", [
                rtrim($this->getLibraries()),
                rtrim($this->getQueryTemplate()),
                $this->getPlaceholderMain(),
                $this->getFooter(),
            ]) . "\n";
    }

    /**
     * Собирает финальный рабочий Python-скрипт из шаблона (импорты + query())
     * и тела main(), сгенерированного моделью. Именно результат этого метода
     * нужно сохранять в файл — а не сырой ответ AI.
     */
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
