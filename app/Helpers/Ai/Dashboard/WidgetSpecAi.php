<?php

namespace App\Helpers\Ai\Dashboard;

use App\Helpers\Ai\AIService;
use App\Helpers\Widget\WidgetQueryComposer;

class WidgetSpecAi
{

    private const MAX_TOKENS = 900;

    public function plan(
        string $instruction,
        string $family,
        ?string $type,
        array $schema,
        array $slots
    ): array {
        return $this->ask(
            $this->prompt($instruction, $family, $type, $schema, $slots)
        );
    }

    public function repair(
        string $instruction,
        string $family,
        ?string $type,
        array $schema,
        array $slots,
        array $brokenBuilder,
        string $error
    ): array {
        $broken = json_encode($brokenBuilder, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $prompt = $this->prompt($instruction, $family, $type, $schema, $slots)."\n\n"
            ."ПРЕДЫДУЩАЯ ПОПЫТКА НЕ ПОДОШЛА\n"
            ."Настройки: {$broken}\n"
            ."Причина: {$error}\n"
            ."Исправь именно её. Если причина в том, что задача не выражается "
            ."этими настройками, верни needs_sql: true.";

        return $this->ask($prompt);
    }

    private function prompt(
        string $instruction,
        string $family,
        ?string $type,
        array $schema,
        array $slots
    ): string {
        $tables = $this->schemaBlock($schema);
        $aggregates = implode(', ', array_keys(WidgetQueryComposer::AGGREGATES));
        $grains = implode(', ', array_keys(WidgetQueryComposer::GRAINS));
        $operators = implode(', ', array_keys(WidgetQueryComposer::OPERATORS));

        $dimensionsRule = $this->slotRule('разбивок', $slots['dimensions'] ?? []);
        $metricsRule = $this->slotRule('метрик', $slots['metrics'] ?? []);
        $hint = $slots['hint'] ?? '';
        $typeLine = $type ? " (вариант «{$type}»)" : '';

        return <<<TEXT
ЗАДАЧА ВИДЖЕТА
{$instruction}

Виджет: {$family}{$typeLine}
{$hint}
Разбивок: {$dimensionsRule}. Метрик: {$metricsRule}.

ТАБЛИЦЫ ИСТОЧНИКА
{$tables}

ЧТО МОЖНО ИСПОЛЬЗОВАТЬ
Функции: {$aggregates}
Округление дат: {$grains}
Условия: {$operators}

ОТВЕТ — ТОЛЬКО JSON
{
  "table": "имя одной таблицы из списка",
  "metrics": [{"agg": "sum", "column": "имя колонки", "label": "подпись на языке задачи"}],
  "dimensions": [{"column": "имя колонки", "grain": "month если это дата и нужен период"}],
  "filters": [{"column": "имя колонки", "op": "=", "value": "значение"}],
  "limit": 10,
  "needs_sql": false,
  "reason": ""
}

ПРАВИЛА
1. Колонки — только из списка выше, буква в букву. Ничего не выдумывай.
2. Бери МИНИМУМ разрезов, которых требует задача. Вторую разбивку добавляй,
   только если в задаче прямо сказано сравнивать по второму признаку
   («по месяцам в разрезе статусов»). «Заказы по месяцам» — это ОДНА
   разбивка; лишний разрез превращает понятный график в кашу.
3. Метрик тоже ровно столько, сколько названо в задаче. Не добавляй
   «полезные» показатели, о которых не просили.
4. Для "count" колонка не нужна, для "sum" и "avg" колонка обязана быть числовой.
5. "grain" ставь только для колонок с датой и только когда нужна ось времени.
6. "label" — короткое название метрики НА ЯЗЫКЕ ЗАДАЧИ, с заглавной буквы;
   оно попадёт в легенду и подписи, которые читает человек. Задача на русском —
   подпись на русском, даже если колонки называются по-английски: «Клиентов»,
   а не «Customers». Язык имён колонок значения не имеет. Если в задаче
   название дано дословно — повтори его как есть, не переводя и не сокращая.
7. "limit" — сколько строк показать; для топ-списков 5–20, для оси времени больше.
8. Если задача требует связи нескольких таблиц, окон или подзапросов —
   верни "needs_sql": true и объясни в "reason" одной фразой. Не пытайся
   выразить это настройками.
9. Если для "filters" нужно конкретное значение колонки (статус, стадия,
   категория и т.п.) — бери его СЛОВО В СЛОВО из "примеры значений" рядом
   с этой колонкой в схеме выше. Нет секции "примеры значений" у колонки
   или среди них нет нужного значения — НЕ пиши по нему фильтр со значением
   (придуманное значение отфильтрует все строки и виджет останется пустым);
   вместо этого используй колонку как разрез (dimension) без фильтра.
TEXT;
    }

    private function schemaBlock(array $schema): string
    {
        $lines = [];

        foreach ($schema as $table => $columns) {
            $parts = [];

            foreach ($columns as $name => $meta) {
                if (!is_string($name)) {
                    $parts[] = is_array($meta) ? (string) ($meta['type'] ?? '') : (string) $meta;

                    continue;
                }

                $type = is_array($meta) ? (string) ($meta['type'] ?? 'unknown') : (string) $meta;
                $samples = is_array($meta) ? ($meta['samples'] ?? []) : [];

                $part = "{$name} ({$type})";

                if ($samples !== []) {
                    $part .= ' — примеры значений: '.implode(', ', $samples);
                }

                $parts[] = $part;
            }

            $lines[] = $table.': '.implode(', ', $parts);
        }

        return implode("\n", $lines);
    }

    private function slotRule(string $what, array $slot): string
    {
        $min = $slot['min'] ?? 0;
        $max = $slot['max'] ?? 10;

        if ($min === $max) {
            return "ровно {$min}";
        }

        return $min > 0 ? "от {$min} до {$max}" : "до {$max}, можно без {$what}";
    }

    private function ask(string $prompt): array
    {
        $system = 'Ты аналитик данных. Ты не пишешь SQL — ты выбираешь таблицу, '
            .'метрики и разрезы для виджета дашборда. Отвечаешь только валидным '
            .'JSON без markdown и пояснений вне JSON.';

        $response = (new AIService(responseFormat: 'json', tokens: self::MAX_TOKENS))
            ->ask($prompt, $system);

        $content = $response['content'] ?? [];
        $tokens = $response['total_tokens'] ?? 0;
        $apiError = $response['api_error'] ?? null;

        if (!is_array($content)) {
            return [
                'ok' => false,
                'builder' => null,
                'needs_sql' => false,
                'message' => 'Не удалось разобрать ответ модели.',
                'api_error' => $apiError,
                'total_tokens' => $tokens,
            ];
        }

        if (!empty($content['needs_sql'])) {
            return [
                'ok' => false,
                'builder' => null,
                'needs_sql' => true,
                'message' => $content['reason'] ?? null,
                'api_error' => $apiError,
                'total_tokens' => $tokens,
            ];
        }

        if (empty($content['table'])) {
            return [
                'ok' => false,
                'builder' => null,
                'needs_sql' => false,
                'message' => $content['reason'] ?? 'Модель не выбрала таблицу.',
                'api_error' => $apiError,
                'total_tokens' => $tokens,
            ];
        }

        return [
            'ok' => true,
            'builder' => [
                'table' => (string) $content['table'],
                'metrics' => $this->listOf($content['metrics'] ?? []),
                'dimensions' => $this->listOf($content['dimensions'] ?? []),
                'filters' => $this->listOf($content['filters'] ?? []),
                'limit' => (int) ($content['limit'] ?? WidgetQueryComposer::DEFAULT_LIMIT),
            ],
            'needs_sql' => false,
            'message' => null,
            'api_error' => $apiError,
            'total_tokens' => $tokens,
        ];
    }

    private function listOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
