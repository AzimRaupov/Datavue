<?php

namespace App\Helpers\Ai\Providers;

/**
 * Генерация python-кода виджета для PostgreSQL.
 */
class PostgresProviderAi extends SqlProviderAi
{
    protected function dialectName(): string
    {
        return 'PostgreSQL';
    }

    protected function dialectRules(): string
    {
        return <<<TEXT
- Плейсхолдеры параметров в query() — %s (psycopg2).
- Идентификаторы цитируются двойными кавычками: "таблица"."колонка". Имена, созданные
  без кавычек, приведены к нижнему регистру — не пиши их в CamelCase.
- Ограничение выборки — LIMIT n.
- Деление целых даёт целое: для доли приводи к дробному, например amount::numeric.
- Усечение периода — DATE_TRUNC('month', колонка), форматирование — TO_CHAR(колонка, 'YYYY-MM').
- Конкатенация строк — оператор ||, а не CONCAT-специфика MySQL.
- В GROUP BY нельзя ссылаться на алиас из SELECT — повторяй выражение целиком.
TEXT;
    }
}
