<?php

namespace App\Helpers\Ai\Providers;

class MysqlProviderAi extends SqlProviderAi
{
    protected function dialectName(): string
    {
        return 'MySQL';
    }

    protected function dialectRules(): string
    {
        return <<<TEXT
- Плейсхолдеры параметров в query() — %s.
- Идентификаторы при необходимости цитируются обратными кавычками: `таблица`.`колонка`.
- Ограничение выборки — LIMIT n.
- Для форматирования дат используй DATE_FORMAT(), для усечения периода — DATE_FORMAT() или YEAR()/MONTH().
TEXT;
    }
}
