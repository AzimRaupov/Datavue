<?php

namespace App\Helpers\Ai\Dashboard;

use App\Helpers\Ai\AIService;
use App\Helpers\Widget\WidgetQueryComposer;

/**
 * Спрашивает у модели НЕ запрос, а решение: что считать и в каком разрезе.
 *
 * Ответ — та же декларация, которую человек собирает слотами в конструкторе:
 *
 *   { "table": "orders",
 *     "metrics": [ {"agg": "sum", "column": "amount", "label": "Выручка"} ],
 *     "dimensions": [ {"column": "country"} ],
 *     "filters": [], "limit": 10 }
 *
 * SQL из неё собирает WidgetQueryComposer — тот самый, что обслуживает
 * конструктор. Отсюда три следствия, ради которых это и сделано:
 *
 *   Промпт короче в разы. Не нужны ни правила диалекта, ни шаблон рантайма,
 *   ни контракт имён выходных колонок, ни примеры на каждую форму: модель
 *   не пишет SQL и ошибиться в нём не может.
 *
 *   Ошибки становятся невозможными целыми классами. Опечатка в колонке,
 *   забытый GROUP BY, чужой диалект, перепутанные псевдонимы — всё это
 *   отсекается проверкой декларации по схеме, ещё до обращения к базе.
 *
 *   Правила ровно одни. Конструктор и генерация собирают запрос одним кодом,
 *   поэтому «у человека работает, а у модели нет» здесь неоткуда взяться.
 *
 * Чего декларацией не выразить — джойны, оконные функции, подзапросы —
 * модель помечает флагом needs_sql, и такие виджеты уходят на путь,
 * где запрос пишется текстом.
 */
class WidgetSpecAi
{
    /**
     * Потолок ответа. Декларация — это десяток строк JSON; просить больше
     * незачем, а лимит заодно не даёт модели уйти в рассуждения.
     */
    private const MAX_TOKENS = 900;

    /**
     * @return array{ok: bool, builder: ?array, needs_sql: bool, message: ?string, api_error: ?string, total_tokens: int}
     */
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

    /**
     * Починка: модель получает свою же декларацию и текст ошибки.
     *
     * Отдельный метод, а не повтор запроса, потому что иначе модель
     * закономерно повторяет прежний ответ — она не знает, что он не подошёл.
     *
     * @return array{ok: bool, builder: ?array, needs_sql: bool, message: ?string, api_error: ?string, total_tokens: int}
     */
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

    /**
     * Промпт целиком. Он намеренно короткий: всё, что платформа знает сама
     * (диалект, имена выходных колонок, форма результата), модели не даётся.
     */
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
6. "label" — короткое название метрики на языке задачи, с заглавной буквы;
   оно попадёт в легенду и подписи. Если в задаче название дано дословно —
   повтори его как есть, не переводя и не сокращая.
7. "limit" — сколько строк показать; для топ-списков 5–20, для оси времени больше.
8. Если задача требует связи нескольких таблиц, окон или подзапросов —
   верни "needs_sql": true и объясни в "reason" одной фразой. Не пытайся
   выразить это настройками.
TEXT;
    }

    /**
     * Схема в компактном виде.
     *
     * JSON со связями, числом строк и уверенностью занимал больше половины
     * промпта, а для выбора «какую колонку сложить и по какой разбить» из
     * него нужны только имя и тип.
     */
    private function schemaBlock(array $schema): string
    {
        $lines = [];

        foreach ($schema as $table => $columns) {
            $parts = [];

            foreach ($columns as $name => $type) {
                $parts[] = is_string($name) ? "{$name} ({$type})" : (string) $type;
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

    /**
     * @return array{ok: bool, builder: ?array, needs_sql: bool, message: ?string, api_error: ?string, total_tokens: int}
     */
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

        // Модель сама признала, что настройками не обойтись.
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

    /**
     * @return array<int, array>
     */
    private function listOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
