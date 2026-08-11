<?php

namespace App\Helpers\Ai\Providers;

/**
 * Генерация python-кода виджета для SQLite.
 */
class SqliteProviderAi extends SqlProviderAi
{
    protected function dialectName(): string
    {
        return 'SQLite';
    }

    protected function dialectRules(): string
    {
        return <<<TEXT
- Плейсхолдеры параметров в query() — ?.
- Идентификаторы цитируются двойными кавычками: "таблица"."колонка".
- Ограничение выборки — LIMIT n.
- Типы хранятся нестрого: числа в текстовых колонках приводи явно через CAST(x AS REAL).
- Дат как отдельного типа нет. Работай через строковые функции:
  strftime('%Y-%m', колонка) для месяца, date(колонка) для дня.
  Если дата хранится числом (unixtime), используй datetime(колонка, 'unixepoch').
- Нет функций RIGHT JOIN и FULL JOIN — перестраивай запрос на LEFT JOIN.
- Деление целых даёт целое: для доли умножай на 1.0 или используй CAST.
TEXT;
    }
}
