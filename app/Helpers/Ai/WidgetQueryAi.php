<?php

namespace App\Helpers\Ai;

use App\Helpers\Ai\Providers\ProviderAiFactory;
use App\Helpers\Ai\Providers\SqlProviderAi;
use App\Helpers\Widget\WidgetShapeMapper;

/**
 * Просит у модели SQL-спецификацию виджета вместо Python-программы.
 *
 * Что изменилось по сути: раньше модель писала программу, которая и считала,
 * и собирала вложенный JSON под схему виджета. Разбор 87 таких скриптов
 * показал, что считал всегда SQL, а Python лишь перекладывал строки —
 * причём вложенность модель изобретала заново на каждом виджете и там же
 * чаще всего ошибалась.
 *
 * Теперь модель отвечает ровно за одно: написать запрос, возвращающий
 * ПЛОСКИЕ строки с фиксированными именами колонок. Вложенность собирает
 * WidgetShapeMapper — один раз и под тестами.
 */
class WidgetQueryAi
{
    private SqlProviderAi $providerAi;

    public function __construct($dataSource)
    {
        // Через фабрику, а не своим match'ем: копия списка типов здесь уже
        // однажды разошлась с фабрикой, и новый тип источника пришлось бы
        // не забыть дописать в двух местах.
        $this->providerAi = ProviderAiFactory::for($dataSource);
    }

    /**
     * @return array{total_tokens: int, spec: ?array, filters: array, message: ?string}
     */
    public function generate(
        string $instruction,
        string $family,
        ?string $type,
        array $tablesScheme,
        array $filterCandidates = []
    ): array {
        $shape = WidgetShapeMapper::shapeFor($family);

        return $this->ask(
            $this->buildPrompt($instruction, $family, $type, $shape, $tablesScheme, $filterCandidates),
            $this->systemPrompt()
        );
    }

    /**
     * Починка после ошибки.
     *
     * Текст ошибки отдаём дословно: база называет причину точно
     * («Unknown column 'orderdate' in 'field list'»), и по такому сообщению
     * модель исправляет запрос с первой попытки. Python-трейсбек этого
     * не давал — там приходилось угадывать, что пошло не так.
     *
     * @return array{total_tokens: int, spec: ?array, filters: array, message: ?string}
     */
    public function repair(
        string $instruction,
        string $family,
        ?string $type,
        array $tablesScheme,
        array $brokenSpec,
        string $error,
        array $filterCandidates = []
    ): array {
        $shape = WidgetShapeMapper::shapeFor($family);

        $specJson = json_encode($brokenSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $prompt = $this->buildPrompt($instruction, $family, $type, $shape, $tablesScheme, $filterCandidates)
            . <<<TEXT


========================
ПРЕДЫДУЩАЯ ПОПЫТКА НЕ СРАБОТАЛА
========================
Спецификация:
{$specJson}

Ошибка:
{$error}

Исправь запрос так, чтобы ошибка ушла. Не меняй смысл виджета.
Если нужных данных в схеме нет — верни спецификацию, дающую пустой результат
правильной формы, и объясни причину в поле "message".
TEXT;

        return $this->ask($prompt, $this->systemPrompt());
    }

    private function systemPrompt(): string
    {
        $dialect = $this->providerAi->dialectHints()['name'];

        return <<<TEXT
Ты — аналитик, который пишет SQL-запросы для виджетов дашборда на {$dialect}.

Твоя работа — запросы, возвращающие ПЛОСКИЕ строки с заранее заданными
именами колонок. Ты НЕ собираешь вложенный JSON: его соберёт платформа.

Жёсткие правила:
1. Только SELECT или WITH. Никаких INSERT, UPDATE, DELETE, CREATE и подобного.
2. В каждом запросе — одна инструкция, без символа ";".
3. Используй только таблицы и колонки из переданной схемы. Ничего не выдумывай.
4. Имена выходных колонок задай через AS ровно так, как требует форма.
5. Запросов может быть несколько: их строки склеиваются в один набор.
   Если задачу проще решить двумя простыми запросами, чем одним сложным —
   пиши два. Это не ошибка, а предпочтительный вариант.
6. Ответ — только JSON, без markdown и без пояснений вне JSON.
7. Если в WHERE/HAVING нужно сравнить колонку с конкретным текстовым значением
   (статус, категория, стадия и т.п.) — бери его СЛОВО В СЛОВО из поля
   "sample_values" этой колонки в схеме. Нет у колонки такого поля или среди
   значений нет нужного — не пиши условие с придуманным значением (оно не
   совпадёт ни с одной строкой, и результат окажется пустым); вместо этого
   верни колонку как есть (GROUP BY/SELECT) без фильтра по значению.
TEXT;
    }

    private function buildPrompt(
        string $instruction,
        string $family,
        ?string $type,
        string $shape,
        array $tablesScheme,
        array $filterCandidates = [],
        ?string $schemeDescription = null
    ): string {
        $hints = $this->providerAi->dialectHints();
        $filtersBlock = $this->filtersBlock($filterCandidates);
        $tablesJson = json_encode($tablesScheme, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $typeLine = $type ? "Вариант отрисовки: {$type}" : 'Вариант отрисовки по умолчанию';

        return <<<TEXT
========================
ЧТО НУЖНО ПОКАЗАТЬ
========================
{$instruction}

Семейство виджета: {$family}
{$typeLine}

========================
СХЕМА ИСТОЧНИКА
========================
{$tablesJson}

========================
ПРАВИЛА ДИАЛЕКТА
========================
{$hints['rules']}

========================
ФОРМА РЕЗУЛЬТАТА: {$shape}
========================
{$this->shapeContract($shape, $family, $type)}

ПРИМЕР ПРАВИЛЬНОГО ОТВЕТА ДЛЯ ЭТОЙ ФОРМЫ:
{$this->shapeExample($shape, $type)}
{$filtersBlock}
========================
ФОРМАТ ОТВЕТА
========================
Верни ТОЛЬКО JSON:

{
  "queries": { "main": "SELECT ..." },
  "shape": "{$shape}",
  "presentation": {},
  "filters": [],
  "message": ""
}

- "queries.main" — сам запрос одной строкой или с переносами, но без ";".
- "shape" — строго "{$shape}".
- "presentation" — только если это нужно форме (см. выше), иначе пустой объект.
- "filters" — массив ключей фильтров, которые уместны этому виджету (см. выше).
  Пустой массив, если ни один не подходит.
- "message" — заполняй, только если данных не хватило; иначе пустая строка.
TEXT;
    }

    /**
     * Договор по форме: какие колонки обязан вернуть запрос.
     *
     * Это самая важная часть промпта. Имена колонок фиксированы, потому что
     * по ним работает и проверка до сохранения, и раскладка при отрисовке.
     */
    private function shapeContract(string $shape, string $family, ?string $type): string
    {
        return match ($shape) {
            WidgetShapeMapper::SHAPE_SERIES_VALUES => $this->valuesContract($family),

            WidgetShapeMapper::SHAPE_SERIES_MATRIX => $this->matrixContract($family),

            WidgetShapeMapper::SHAPE_POINTS => $type === 'bubble'
                ? "Колонки: series (имя набора), x (число), y (число), z (число — размер точки).\n"
                    . 'Одна строка — одна точка.'
                : "Колонки: series (имя набора), x (число), y (число).\n"
                    . "Одна строка — одна точка. Если набор один, верни в series одно и то же имя.",

            WidgetShapeMapper::SHAPE_COUNTERS => $this->countersContract($type),

            WidgetShapeMapper::SHAPE_ROWS =>
                "Колонки — любые, какие нужны в таблице. Их имена станут заголовками "
                . "столбцов, поэтому задай их через AS по-человечески (например, "
                . '"Клиент", "Выручка"). Порядок колонок = порядок столбцов.'."\n"
                . 'LIMIT НЕ СТАВЬ: таблица всегда выводится постранично, и лимит '
                . 'в запросе ограничил бы общее число строк — листать стало бы некуда. '
                . 'Сортировку через ORDER BY задавай.',

            default => throw new RuntimeException("Неизвестная форма: {$shape}"),
        };
    }

    /**
     * Блок фильтров в промпте.
     *
     * Кандидаты отобраны заранее и механически: обязательные сюда не попадают
     * вовсе, датовые — только если в схеме реально есть дата. Поэтому блок
     * короткий, а при отсутствии кандидатов исчезает целиком и не стоит
     * ни одного лишнего токена.
     */
    private function filtersBlock(array $candidates): string
    {
        if ($candidates === []) {
            return "\n";
        }

        $lines = '';

        foreach ($candidates as $filter) {
            $lines .= "- {$filter['key']}: {$filter['label']} — {$filter['description']}\n";
        }

        return <<<TEXT


========================
ФИЛЬТРЫ, КОТОРЫЕ МОЖНО ВКЛЮЧИТЬ
========================
Пользователь сможет управлять виджетом на готовом дашборде. Выбери те,
что осмысленны именно для этих данных, и перечисли их ключи в поле "filters".

{$lines}
ВАЖНО про date_range и day. Если включаешь их, условие ДОЛЖНО быть внутри
запроса, до агрегации, и написано так, чтобы работать и без выбранного периода:

  WHERE (:date_from IS NULL OR колонка_даты >= :date_from)
    AND (:date_to   IS NULL OR колонка_даты <= :date_to)

Для day — один плейсхолдер :day по тому же образцу.
Пагинацию и поиск не пиши: LIMIT, OFFSET и поиск добавит платформа сама.

TEXT;
    }

    /**
     * Готовый пример ответа под форму.
     *
     * Показать образец дешевле и надёжнее, чем описывать словами: у прежнего
     * Python-пути перед глазами модели был полный каркас скрипта, и это сильно
     * помогало. При переходе на SQL образец пропал — возвращаем его.
     */
    private function shapeExample(string $shape, ?string $type): string
    {
        return match ($shape) {
            WidgetShapeMapper::SHAPE_SERIES_VALUES => <<<'JSON'
{
  "queries": {
    "main": "SELECT c.country AS label, COUNT(*) AS value FROM customers c GROUP BY c.country ORDER BY value DESC LIMIT 10"
  },
  "shape": "series_values"
}
JSON,

            WidgetShapeMapper::SHAPE_SERIES_MATRIX => <<<'JSON'
{
  "queries": {
    "main": "SELECT pl.productLine AS series, DATE_FORMAT(o.orderDate, '%Y-%m') AS category, SUM(od.quantityOrdered * od.priceEach) AS value FROM orders o JOIN orderdetails od ON od.orderNumber = o.orderNumber JOIN products p ON p.productCode = od.productCode JOIN productlines pl ON pl.productLine = p.productLine GROUP BY pl.productLine, DATE_FORMAT(o.orderDate, '%Y-%m') ORDER BY category"
  },
  "shape": "series_matrix"
}
JSON,

            WidgetShapeMapper::SHAPE_POINTS => $type === 'bubble'
                ? <<<'JSON'
{
  "queries": {
    "main": "SELECT c.country AS series, c.creditLimit AS x, SUM(od.quantityOrdered * od.priceEach) AS y, COUNT(DISTINCT o.orderNumber) AS z FROM customers c JOIN orders o ON o.customerNumber = c.customerNumber JOIN orderdetails od ON od.orderNumber = o.orderNumber GROUP BY c.country, c.creditLimit"
  },
  "shape": "points"
}
JSON
                : <<<'JSON'
{
  "queries": {
    "main": "SELECT 'Клиенты' AS series, c.creditLimit AS x, SUM(od.quantityOrdered * od.priceEach) AS y FROM customers c JOIN orders o ON o.customerNumber = c.customerNumber JOIN orderdetails od ON od.orderNumber = o.orderNumber GROUP BY c.customerNumber, c.creditLimit"
  },
  "shape": "points"
}
JSON,

            // Счётчики — главный выигрыш от нескольких запросов: раньше их
            // приходилось сшивать через UNION ALL, подгоняя типы колонок,
            // и именно там модель чаще всего ошибалась.
            WidgetShapeMapper::SHAPE_COUNTERS => <<<'JSON'
{
  "queries": {
    "clients":  "SELECT 'Клиентов' AS name, COUNT(*) AS value FROM customers",
    "orders":   "SELECT 'Заказов' AS name, COUNT(*) AS value FROM orders",
    "revenue":  "SELECT 'Выручка' AS name, SUM(quantityOrdered * priceEach) AS value, ' ₽' AS suffix FROM orderdetails"
  },
  "shape": "counters"
}
JSON,

            WidgetShapeMapper::SHAPE_ROWS => <<<'JSON'
{
  "queries": {
    "main": "SELECT o.orderNumber AS `Заказ`, o.orderDate AS `Дата`, c.customerName AS `Клиент`, SUM(od.quantityOrdered * od.priceEach) AS `Сумма` FROM orders o JOIN customers c ON c.customerNumber = o.customerNumber JOIN orderdetails od ON od.orderNumber = o.orderNumber GROUP BY o.orderNumber, o.orderDate, c.customerName ORDER BY `Сумма` DESC"
  },
  "shape": "rows"
}
JSON,

            default => '{}',
        };
    }

    private function valuesContract(string $family): string
    {
        $base = "Колонки: label (подпись, текст), value (число).\n"
            . "Одна строка — один сегмент. Сортировку задавай через ORDER BY, "
            . "количество — через LIMIT.";

        return match ($family) {
            'map' => $base . "\nВНИМАНИЕ: это карта — в label должен быть "
                . "двухбуквенный код страны в формате ISO 3166-1 alpha-2 (RU, US, DE), "
                . "а не её название.",

            'radial' => $base . "\nЭто индикатор выполнения: в value ожидается "
                . "процент от 0 до 100, обычно одна-три строки.",

            'funnel' => $base . "\nЭто воронка: строки должны идти от самого "
                . "широкого этапа к самому узкому (ORDER BY value DESC).",

            default => $base,
        };
    }

    private function matrixContract(string $family): string
    {
        $base = "Колонки: series (имя ряда, текст), category (подпись по оси, текст), "
            . "value (число).\n"
            . "Одна строка — одно пересечение ряда и категории. Платформа сама "
            . "развернёт это в ряды и ось, а недостающие пересечения заполнит нулями — "
            . "перечислять все комбинации не нужно.\n"
            . "Порядок категорий на оси = порядок из ORDER BY, поэтому сортируй "
            . "по категории (для дат — по возрастанию).\n\n"
            . "ГЛАВНОЕ, ГДЕ ЛЕГКО ОШИБИТЬСЯ — что класть в series, а что в category:\n"
            . "  category — то, что откладывается ПО ОСИ и что сравнивают между собой "
            . "(страны, месяцы, товары).\n"
            . "  series — НАБОР значений, каждый своим цветом. Рядов обычно 1–4.\n\n"
            . "Если сравнивается одна величина в разрезе чего-то одного "
            . "(«продажи по странам», «выручка по месяцам»), ряд ВСЕГДА ОДИН, "
            . "и его имя — строковая константа:\n"
            . "  SELECT \'Выручка\' AS series, country AS category, SUM(...) AS value\n\n"
            . "Класть страны или месяцы в series НЕЛЬЗЯ: каждое значение станет "
            . "отдельным рядом со своим цветом, ось окажется из одного деления, "
            . "и график будет нечитаемым.\n"
            . "Несколько рядов нужны только тогда, когда в запросе есть ВТОРОЙ "
            . "разрез — например «выручка по месяцам в разбивке по категориям товара».";

        return match ($family) {
            'combo' => $base . "\n\nЭто комбинированный график: часть рядов рисуется "
                . "столбцами, часть линией. Укажи это в presentation:\n"
                . '"presentation": { "series_kinds": { "Выручка": "column", "Средний чек": "line" } }',

            'radar' => $base . "\nДля радара категорий обычно 3–8: больше не читается.",

            'heatmap' => $base . "\nДля тепловой карты series — строки карты, "
                . "category — столбцы.",

            default => $base,
        };
    }

    private function countersContract(?string $type): string
    {
        $base = "Колонки: name (подпись счётчика, текст), value (число).\n"
            . "Одна строка — один счётчик. Обычно 2–4 счётчика.\n"
            . "ЛУЧШИЙ СПОСОБ: отдельный запрос на каждый счётчик — так проще "
            . "и надёжнее, чем сшивать их UNION ALL с подгонкой типов колонок. "
            . "Их строки платформа склеит сама, порядок запросов = порядок плиток.\n"
            . "Единицы измерения (₽, %, шт.) возвращай колонками prefix и suffix.";

        return $type === 'with-progress'
            ? $base . "\nВариант с прогрессом: добавь колонку percent "
                . '(выполнение плана от 0 до 100).'
            : $base;
    }

    /**
     * Приводит ответ модели к списку «имя => SQL».
     *
     * Модель может прислать и строку, и объект с любым числом запросов —
     * несколько простых запросов теперь предпочтительнее одного сложного.
     *
     * @return array<string, string>
     */
    private function normalizeQueries(mixed $queries): array
    {
        if (is_string($queries) && trim($queries) !== '') {
            return ['main' => trim($queries)];
        }

        if (!is_array($queries)) {
            return [];
        }

        $result = [];

        foreach ($queries as $name => $sql) {
            if (is_string($sql) && trim($sql) !== '') {
                $result[(string) $name] = trim($sql);
            }
        }

        return $result;
    }

    /**
     * @return array{total_tokens: int, spec: ?array, filters: array, message: ?string}
     */
    private function ask(string $prompt, string $system): array
    {
        $response = (new AIService(responseFormat: 'json', tokens: 2500))->ask($prompt, $system);

        $content = $response['content'] ?? [];

        $queries = $this->normalizeQueries($content['queries'] ?? null);

        if (!is_array($content) || $queries === []) {
            return [
                'total_tokens' => $response['total_tokens'] ?? 0,
                // Отличаем сбой связи от плохого ответа модели: чинить
                // в первом случае нечего, надо просто повторить.
                'api_error' => $response['api_error'] ?? null,
                'spec' => null,
                'filters' => [],
                'message' => is_array($content)
                    ? ($content['message'] ?? 'Модель не вернула запрос.')
                    : 'Не удалось разобрать ответ модели.',
            ];
        }

        return [
            'total_tokens' => $response['total_tokens'] ?? 0,
            'api_error' => $response['api_error'] ?? null,
            'spec' => [
                'queries' => $queries,
                'shape' => $content['shape'] ?? null,
                'presentation' => is_array($content['presentation'] ?? null)
                    ? $content['presentation']
                    : [],
            ],
            'filters' => is_array($content['filters'] ?? null)
                ? array_values(array_filter($content['filters'], 'is_string'))
                : [],
            'message' => $content['message'] ?? null,
        ];
    }
}
