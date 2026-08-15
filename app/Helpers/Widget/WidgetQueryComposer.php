<?php

namespace App\Helpers\Widget;

use App\Helpers\DataSource\SourceSchema;
use App\Helpers\DataSource\SqlParameterBinder;
use App\Models\DataSource;
use RuntimeException;

/**
 * Собирает SQL из настроек конструктора — то, что в Superset делает Explore,
 * а в Metabase «notebook»: аналитик выбирает таблицу, метрики и разбивку,
 * а запрос пишет платформа.
 *
 * Декларация (её же показывает интерфейс):
 *
 *   {
 *     "table": "orders",
 *     "metrics":    [ { "agg": "sum", "column": "amount", "label": "Выручка" } ],
 *     "dimensions": [ { "column": "country" },
 *                     { "column": "created_at", "grain": "month" } ],
 *     "filters":    [ { "column": "status", "op": "=", "value": "shipped" } ],
 *     "sort":       { "by": "metric", "index": 0, "dir": "desc" },
 *     "limit": 10
 *   }
 *
 * Три принципа, ради которых это отдельный класс:
 *
 *   1. Пользовательского SQL здесь нет вообще. В запрос попадают только имена
 *      таблиц и колонок, СУЩЕСТВОВАНИЕ которых подтверждено схемой источника,
 *      и значения, приведённые к типу колонки. Опечатка отклоняется здесь,
 *      а не превращается в кусок запроса.
 *
 *   2. Раскладку по слотам виджета делает платформа. Аналитик говорит «сумма
 *      по странам», а куда пойдёт страна — в category или в label — решает
 *      форма семейства. Так один и тот же набор настроек рисуется и столбцами,
 *      и круговой, без переписывания.
 *
 *   3. Результат — обычный SQL, который видно в интерфейсе и можно открыть
 *      в режиме запроса и дописать руками. Конструктор не запирает: он даёт
 *      быстрый старт, а дальше не мешает.
 */
class WidgetQueryComposer
{
    /** Агрегаты, доступные в конструкторе. */
    public const AGGREGATES = [
        'count' => 'Количество строк',
        'count_distinct' => 'Количество уникальных',
        'sum' => 'Сумма',
        'avg' => 'Среднее',
        'min' => 'Минимум',
        'max' => 'Максимум',
    ];

    /** Агрегаты, которым колонка не нужна. */
    private const AGGREGATES_WITHOUT_COLUMN = ['count'];

    /** Агрегаты, которым нужна числовая колонка. */
    private const NUMERIC_AGGREGATES = ['sum', 'avg'];

    /** Округление дат для оси времени. */
    public const GRAINS = [
        'day' => 'По дням',
        'week' => 'По неделям',
        'month' => 'По месяцам',
        'quarter' => 'По кварталам',
        'year' => 'По годам',
    ];

    /** Операторы фильтров. */
    public const OPERATORS = [
        '=' => 'равно',
        '!=' => 'не равно',
        '>' => 'больше',
        '>=' => 'больше или равно',
        '<' => 'меньше',
        '<=' => 'меньше или равно',
        'in' => 'входит в список',
        'contains' => 'содержит',
        'starts_with' => 'начинается с',
        'between' => 'в диапазоне',
        'is_null' => 'не заполнено',
        'not_null' => 'заполнено',
    ];

    /** Операторы, которым значение не нужно. */
    private const OPERATORS_WITHOUT_VALUE = ['is_null', 'not_null'];

    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 5000;

    private string $driver;
    private SqlParameterBinder $binder;

    /** @var array<string, array<string, string>> таблица => колонка => вид */
    private array $schema;

    public function __construct(private DataSource $dataSource)
    {
        $this->driver = $dataSource->type->name ?? 'mysql';
        $this->schema = SourceSchema::map($dataSource);

        // Значения подставляются в текст запроса, а не передаются отдельно, —
        // и это осознанно: собранный SQL должен быть самодостаточным, чтобы
        // его можно было показать, сохранить и открыть в режиме запроса.
        // Безопасность держится не на подготовленных выражениях, а на том,
        // что каждое значение проходит приведение к типу своей колонки:
        // в literal() попадает либо число, либо строка с экранированием.
        $this->binder = new SqlParameterBinder(supportsBindings: false);
    }

    /**
     * @param array $builder Настройки конструктора
     * @param string $family Семейство виджета — определяет раскладку по слотам
     *
     * @return array{ok: bool, sql: ?string, errors: array<int, string>}
     */
    public function compose(array $builder, string $family, ?string $type = null): array
    {
        try {
            $table = $this->requireTable($builder['table'] ?? null);
            $metrics = $this->readMetrics($builder['metrics'] ?? [], $table);
            $dimensions = $this->readDimensions($builder['dimensions'] ?? [], $table);
            $where = $this->readFilters($builder['filters'] ?? [], $table);
            $limit = $this->readLimit($builder['limit'] ?? null);

            $shape = WidgetShapeMapper::shapeFor($family);

            // Без метрик считать нечего. Проверка нужна именно здесь: раньше
            // пустой список молча доходил до сборки, и получался обрубок вида
            // «SELECT country AS label, AS value» — база отвечала на него
            // невнятной ошибкой синтаксиса вместо понятной подсказки.
            if ($metrics === [] && $shape !== WidgetShapeMapper::SHAPE_ROWS) {
                throw new RuntimeException('Добавьте хотя бы одну метрику — иначе считать нечего.');
            }

            $sql = match ($shape) {
                WidgetShapeMapper::SHAPE_SERIES_MATRIX => $this->composeMatrix($table, $metrics, $dimensions, $where, $builder, $limit),
                WidgetShapeMapper::SHAPE_SERIES_VALUES => $this->composeValues($table, $metrics, $dimensions, $where, $builder, $limit),
                WidgetShapeMapper::SHAPE_COUNTERS => $this->composeCounters($table, $metrics, $dimensions, $where, $builder, $limit, $type),
                WidgetShapeMapper::SHAPE_POINTS => $this->composePoints($table, $metrics, $dimensions, $where, $builder, $limit, $type),
                WidgetShapeMapper::SHAPE_ROWS => $this->composeRows($table, $metrics, $dimensions, $where, $builder, $limit),
                default => throw new RuntimeException("Для этого виджета конструктор пока не поддерживается."),
            };

            return [
                'ok' => true,
                'sql' => $sql,
                // Оформление, которое следует из самих настроек. Сейчас это
                // нужно комбо-графику: без указания, какой ряд линия, а какой
                // столбцы, он не отличим от обычной гистограммы — и проверка
                // формы его отклоняет. Просить это у автора незачем: порядок
                // метрик уже всё говорит.
                'presentation' => $this->presentationFor($family, $metrics),
                'errors' => [],
            ];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'sql' => null, 'presentation' => [], 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * @param array<int, array{expression: string, label: string}> $metrics
     */
    private function presentationFor(string $family, array $metrics): array
    {
        if ($family !== 'combo' || count($metrics) < 2) {
            return [];
        }

        $kinds = [];

        foreach ($metrics as $index => $metric) {
            // Первая метрика — столбцы, остальные — линии: так комбо и читают,
            // когда сравнивают объём с относительным показателем.
            $kinds[$metric['label']] = $index === 0 ? 'column' : 'line';
        }

        return ['series_kinds' => $kinds];
    }

    /**
     * Что конструктор ждёт от автора для этого семейства — подсказка интерфейсу.
     *
     * @return array{dimensions: array{min: int, max: int}, metrics: array{min: int, max: int}, hint: string}
     */
    public static function slotsFor(string $family, ?string $type = null): array
    {
        // Комбо-график по определению совмещает столбцы с линией, поэтому ему
        // нужны минимум две метрики на одной оси. С одной метрикой это просто
        // столбцы, и проверка формы такой виджет всё равно отклонит.
        if ($family === 'combo') {
            return [
                'dimensions' => ['min' => 1, 'max' => 1],
                'metrics' => ['min' => 2, 'max' => 10],
                'hint' => 'Разбивка станет осью. Первая метрика рисуется столбцами, '
                    . 'остальные — линиями.',
            ];
        }

        // Карте нужен не любой признак, а код страны: подпись «Россия»
        // на карте не найдётся.
        if ($family === 'map') {
            return [
                'dimensions' => ['min' => 1, 'max' => 1],
                'metrics' => ['min' => 1, 'max' => 1],
                'hint' => 'Разбивка должна давать код страны из двух букв (RU, US, DE).',
            ];
        }

        return match (WidgetShapeMapper::shapeFor($family)) {
            WidgetShapeMapper::SHAPE_SERIES_MATRIX => [
                'dimensions' => ['min' => 1, 'max' => 2],
                'metrics' => ['min' => 1, 'max' => 10],
                'hint' => 'Первая разбивка станет осью. Вторая разбивка или несколько метрик — '
                    . 'отдельными рядами.',
            ],
            WidgetShapeMapper::SHAPE_SERIES_VALUES => [
                'dimensions' => ['min' => 0, 'max' => 1],
                'metrics' => ['min' => 1, 'max' => 10],
                'hint' => 'Разбивка даёт сегменты. Без разбивки сегментом становится каждая метрика.',
            ],
            WidgetShapeMapper::SHAPE_COUNTERS => [
                'dimensions' => ['min' => 0, 'max' => 1],
                'metrics' => ['min' => 1, 'max' => 10],
                'needs_target' => $type === 'with-progress',
                'hint' => $type === 'with-progress'
                    ? 'Каждая метрика — плитка с полосой выполнения. Укажите цель, '
                        . 'от которой считается процент; без цели значение метрики '
                        . 'само считается процентом.'
                    : 'Каждая метрика — отдельная плитка. С разбивкой плитки берутся из её значений.',
            ],
            WidgetShapeMapper::SHAPE_POINTS => [
                'dimensions' => ['min' => 1, 'max' => 1],
                'metrics' => ['min' => $type === 'bubble' ? 3 : 2, 'max' => 3],
                'hint' => $type === 'bubble'
                    ? 'Три метрики: по горизонтали, по вертикали и размер точки.'
                    : 'Две метрики: по горизонтали и по вертикали.',
            ],
            default => [
                'dimensions' => ['min' => 0, 'max' => 10],
                'metrics' => ['min' => 0, 'max' => 10],
                'hint' => 'Колонки таблицы — это разбивки и метрики в том порядке, в котором выбраны.',
            ],
        };
    }

    // -----------------------------------------------------------------
    // Раскладка по формам
    // -----------------------------------------------------------------

    /**
     * series / category / value — столбцы, линия, комбо, радар, тепловая карта.
     */
    private function composeMatrix(
        string $table,
        array $metrics,
        array $dimensions,
        array $where,
        array $builder,
        int $limit
    ): string {
        if ($dimensions === []) {
            throw new RuntimeException('Выберите хотя бы одну разбивку — по ней строится ось.');
        }

        $axis = $dimensions[0];
        $breakdown = $dimensions[1] ?? null;

        // Несколько метрик и вторая разбивка одновременно дали бы ряды вида
        // «Выручка / Москва», которые невозможно прочитать на графике.
        if ($breakdown && count($metrics) > 1) {
            throw new RuntimeException(
                'Вместе со второй разбивкой оставьте одну метрику: иначе рядов станет '
                . 'слишком много и график будет нечитаем.'
            );
        }

        if ($breakdown) {
            // Со второй разбивкой сортируем по оси, а не по величине: строки
            // здесь принадлежат разным рядам, и «топ по значению» перемешал бы
            // категории между ними — ось получилась бы в случайном порядке.
            return $this->select(
                [
                    $breakdown['expression'].' AS '.$this->alias('series'),
                    $axis['expression'].' AS '.$this->alias('category'),
                    $metrics[0]['expression'].' AS '.$this->alias('value'),
                ],
                $table,
                $where,
                [$axis['expression'], $breakdown['expression']],
                $axis['expression'].' ASC',
                $limit
            );
        }

        // Одна метрика — один ряд, его имя берётся из подписи метрики.
        if (count($metrics) === 1) {
            return $this->select(
                [
                    $this->literalString($metrics[0]['label']).' AS '.$this->alias('series'),
                    $axis['expression'].' AS '.$this->alias('category'),
                    $metrics[0]['expression'].' AS '.$this->alias('value'),
                ],
                $table,
                $where,
                [$axis['expression']],
                $this->orderFor($builder, $axis, 'value'),
                $limit
            );
        }

        // Несколько метрик разворачиваются в ряды: по подзапросу на метрику.
        // Так одна и та же ось сравнивает выручку и количество заказов.
        $parts = [];

        foreach ($metrics as $metric) {
            $parts[] = $this->select(
                [
                    $this->literalString($metric['label']).' AS '.$this->alias('series'),
                    $axis['expression'].' AS '.$this->alias('category'),
                    $metric['expression'].' AS '.$this->alias('value'),
                ],
                $table,
                $where,
                [$axis['expression']],
                null,
                null
            );
        }

        return implode("\nUNION ALL\n", $parts)
            ."\nORDER BY ".$this->alias('category')
            ."\nLIMIT ".$limit;
    }

    /**
     * label / value — круговая, воронка, treemap, радиал, карта.
     */
    private function composeValues(
        string $table,
        array $metrics,
        array $dimensions,
        array $where,
        array $builder,
        int $limit
    ): string {
        if ($dimensions !== []) {
            if (count($metrics) > 1) {
                throw new RuntimeException(
                    'С разбивкой у этого виджета остаётся одна метрика: сегменты — это её значения.'
                );
            }

            return $this->select(
                [
                    $dimensions[0]['expression'].' AS '.$this->alias('label'),
                    $metrics[0]['expression'].' AS '.$this->alias('value'),
                ],
                $table,
                $where,
                [$dimensions[0]['expression']],
                $this->orderFor($builder, $dimensions[0], 'value'),
                $limit
            );
        }

        // Без разбивки сегментом становится каждая метрика.
        return $this->unionOfMetrics($table, $metrics, $where, 'label', $limit);
    }

    /**
     * name / value — плитки счётчиков.
     */
    private function composeCounters(
        string $table,
        array $metrics,
        array $dimensions,
        array $where,
        array $builder,
        int $limit,
        ?string $type = null
    ): string {
        // Вариант с полосой выполнения требует третью колонку — процент.
        // Без неё виджет не рисуется: полосе нечего показывать.
        $withProgress = $type === 'with-progress';

        if ($dimensions !== []) {
            if (count($metrics) > 1) {
                throw new RuntimeException('С разбивкой оставьте одну метрику — плитки берутся из её значений.');
            }

            $columns = [
                $dimensions[0]['expression'].' AS '.$this->alias('name'),
                $metrics[0]['expression'].' AS '.$this->alias('value'),
            ];

            if ($withProgress) {
                $columns[] = $this->percentExpression($metrics[0]).' AS '.$this->alias('percent');
            }

            return $this->select(
                $columns,
                $table,
                $where,
                [$dimensions[0]['expression']],
                $this->orderFor($builder, $dimensions[0], 'value'),
                $limit
            );
        }

        return $this->unionOfMetrics($table, $metrics, $where, 'name', $limit, $withProgress);
    }

    /**
     * Процент выполнения для полосы.
     *
     * Цель задаёт автор: «сделано 42 из 100». Если цели нет, значение метрики
     * и есть процент — так считают, когда метрика уже посчитана в процентах
     * (средняя загрузка, доля выполненных). Проценту не дают уйти за пределы
     * шкалы: полоса, залитая на 300%, не читается.
     */
    private function percentExpression(array $metric): string
    {
        $expression = $metric['expression'];

        if (!empty($metric['target'])) {
            $expression = 'ROUND(100 * '.$expression.' / '.$metric['target'].', 1)';
        }

        return 'LEAST(100, GREATEST(0, '.$expression.'))';
    }

    /**
     * Цель метрики — положительное число или ничего.
     */
    private function readTarget(mixed $target): ?float
    {
        if ($target === null || $target === '' || !is_numeric($target)) {
            return null;
        }

        $value = (float) $target;

        // Ноль целью быть не может: делить на него нечем.
        return $value > 0 ? $value : null;
    }

    /**
     * series / x / y [ / z ] — точечная и пузырьковая.
     */
    private function composePoints(
        string $table,
        array $metrics,
        array $dimensions,
        array $where,
        array $builder,
        int $limit,
        ?string $type
    ): string {
        $needed = $type === 'bubble' ? 3 : 2;

        // Без разбивки агрегаты считаются по всей таблице, и на графике
        // оказывается ровно одна точка. Формально это верный запрос, но
        // виджет из него бессмысленный, поэтому останавливаемся здесь.
        if ($dimensions === []) {
            throw new RuntimeException(
                'Добавьте разбивку — иначе все строки схлопнутся в одну точку.'
            );
        }

        if (count($metrics) < $needed) {
            throw new RuntimeException(
                $needed === 3
                    ? 'Пузырьковой нужны три метрики: по горизонтали, по вертикали и размер.'
                    : 'Точечной нужны две метрики: по горизонтали и по вертикали.'
            );
        }

        $columns = [
            $dimensions[0]['expression'].' AS '.$this->alias('series'),
            $metrics[0]['expression'].' AS '.$this->alias('x'),
            $metrics[1]['expression'].' AS '.$this->alias('y'),
        ];

        if ($needed === 3) {
            $columns[] = $metrics[2]['expression'].' AS '.$this->alias('z');
        }

        return $this->select(
            $columns,
            $table,
            $where,
            [$dimensions[0]['expression']],
            null,
            $limit
        );
    }

    /**
     * Таблица: колонки как выбраны, заголовки — из подписей.
     */
    private function composeRows(
        string $table,
        array $metrics,
        array $dimensions,
        array $where,
        array $builder,
        int $limit
    ): string {
        if ($metrics === [] && $dimensions === []) {
            throw new RuntimeException('Выберите хотя бы одну колонку или метрику.');
        }

        $columns = [];
        $group = [];

        foreach ($dimensions as $dimension) {
            $columns[] = $dimension['expression'].' AS '.$this->alias($dimension['label']);
            $group[] = $dimension['expression'];
        }

        foreach ($metrics as $metric) {
            $columns[] = $metric['expression'].' AS '.$this->alias($metric['label']);
        }

        // Без метрик группировать нечего — это выборка строк как есть.
        return $this->select(
            $columns,
            $table,
            $where,
            $metrics === [] ? [] : $group,
            $this->orderFor($builder, $dimensions[0] ?? null, $metrics === [] ? null : $metrics[0]['label']),
            $limit
        );
    }

    /**
     * Каждая метрика — отдельная строка результата.
     */
    private function unionOfMetrics(
        string $table,
        array $metrics,
        array $where,
        string $nameAlias,
        int $limit,
        bool $withProgress = false
    ): string {
        // Здесь строка результата — это метрика, а не запись данных. Лимит,
        // меньший числа метрик, молча срезал бы плитки: пользователь просил
        // три показателя, а получал два.
        $limit = max($limit, count($metrics));

        $parts = [];

        foreach ($metrics as $metric) {
            $columns = [
                $this->literalString($metric['label']).' AS '.$this->alias($nameAlias),
                $metric['expression'].' AS '.$this->alias('value'),
            ];

            if ($withProgress) {
                $columns[] = $this->percentExpression($metric).' AS '.$this->alias('percent');
            }

            $parts[] = $this->select($columns, $table, $where, [], null, null);
        }

        return implode("\nUNION ALL\n", $parts)."\nLIMIT ".$limit;
    }

    // -----------------------------------------------------------------
    // Разбор настроек
    // -----------------------------------------------------------------

    private function requireTable(mixed $table): string
    {
        $table = is_string($table) ? trim($table) : '';

        if ($table === '') {
            throw new RuntimeException('Не выбрана таблица.');
        }

        if (!array_key_exists($table, $this->schema)) {
            throw new RuntimeException("В источнике нет таблицы «{$table}».");
        }

        return $table;
    }

    /**
     * @return array<int, array{expression: string, label: string}>
     */
    private function readMetrics(mixed $metrics, string $table): array
    {
        if (!is_array($metrics)) {
            return [];
        }

        $result = [];

        foreach ($metrics as $metric) {
            if (!is_array($metric)) {
                continue;
            }

            $agg = strtolower(trim((string) ($metric['agg'] ?? '')));

            if (!array_key_exists($agg, self::AGGREGATES)) {
                throw new RuntimeException("Неизвестная функция «{$agg}».");
            }

            if (in_array($agg, self::AGGREGATES_WITHOUT_COLUMN, true)) {
                $result[] = [
                    'expression' => 'COUNT(*)',
                    'label' => trim((string) ($metric['label'] ?? '')) ?: 'Количество',
                    'target' => $this->readTarget($metric['target'] ?? null),
                ];

                continue;
            }

            $column = $this->requireColumn($table, $metric['column'] ?? null);

            if (in_array($agg, self::NUMERIC_AGGREGATES, true) && $this->schema[$table][$column] !== 'number') {
                throw new RuntimeException(
                    "Колонка «{$column}» не числовая — посчитать по ней «".self::AGGREGATES[$agg]."» нельзя."
                );
            }

            $expression = match ($agg) {
                'count_distinct' => 'COUNT(DISTINCT '.$this->quote($column).')',
                default => strtoupper($agg).'('.$this->quote($column).')',
            };

            $result[] = [
                'expression' => $expression,
                'label' => trim((string) ($metric['label'] ?? '')) ?: self::AGGREGATES[$agg].' '.$column,
                // Цель нужна счётчику с полосой выполнения: от неё считается
                // процент. У остальных виджетов поле просто не используется.
                'target' => $this->readTarget($metric['target'] ?? null),
            ];
        }

        if ($result === []) {
            return [];
        }

        return $result;
    }

    /**
     * @return array<int, array{expression: string, label: string, column: string}>
     */
    private function readDimensions(mixed $dimensions, string $table): array
    {
        if (!is_array($dimensions)) {
            return [];
        }

        $result = [];

        foreach ($dimensions as $dimension) {
            if (!is_array($dimension)) {
                continue;
            }

            $column = $this->requireColumn($table, $dimension['column'] ?? null);
            $grain = $dimension['grain'] ?? null;

            $expression = $this->quote($column);

            if ($grain) {
                if (!array_key_exists($grain, self::GRAINS)) {
                    throw new RuntimeException("Неизвестное округление даты «{$grain}».");
                }

                if ($this->schema[$table][$column] !== 'date') {
                    throw new RuntimeException("Колонка «{$column}» не дата — округлять её по периодам нельзя.");
                }

                $expression = $this->grainExpression($expression, $grain);
            }

            $result[] = [
                'expression' => $expression,
                'label' => trim((string) ($dimension['label'] ?? '')) ?: $column,
                'column' => $column,
            ];
        }

        return $result;
    }

    /**
     * @return array<int, string> Готовые условия WHERE
     */
    private function readFilters(mixed $filters, string $table): array
    {
        if (!is_array($filters)) {
            return [];
        }

        $conditions = [];

        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            $column = $this->requireColumn($table, $filter['column'] ?? null);
            $op = trim((string) ($filter['op'] ?? '='));

            if (!array_key_exists($op, self::OPERATORS)) {
                throw new RuntimeException("Неизвестное условие «{$op}».");
            }

            $quoted = $this->quote($column);
            $kind = $this->schema[$table][$column];

            if (in_array($op, self::OPERATORS_WITHOUT_VALUE, true)) {
                $conditions[] = $op === 'is_null' ? "{$quoted} IS NULL" : "{$quoted} IS NOT NULL";

                continue;
            }

            $value = $filter['value'] ?? null;

            if ($value === null || $value === '' || $value === []) {
                throw new RuntimeException("У условия по колонке «{$column}» не задано значение.");
            }

            $conditions[] = match ($op) {
                'in' => $quoted.' IN ('.implode(', ', array_map(
                    fn ($item) => $this->literal($item, $kind),
                    is_array($value) ? $value : preg_split('/\s*,\s*/', (string) $value)
                )).')',

                'contains' => $quoted.' LIKE '.$this->literal('%'.$this->plain($value).'%', 'string'),

                'starts_with' => $quoted.' LIKE '.$this->literal($this->plain($value).'%', 'string'),

                'between' => $this->betweenCondition($quoted, $value, $kind, $column),

                default => $quoted.' '.$op.' '.$this->literal($value, $kind),
            };
        }

        return $conditions;
    }

    private function betweenCondition(string $quoted, mixed $value, string $kind, string $column): string
    {
        $values = is_array($value) ? array_values($value) : preg_split('/\s*,\s*/', (string) $value);

        if (count($values) !== 2) {
            throw new RuntimeException("Для диапазона по колонке «{$column}» нужны два значения.");
        }

        return $quoted.' BETWEEN '.$this->literal($values[0], $kind).' AND '.$this->literal($values[1], $kind);
    }

    private function readLimit(mixed $limit): int
    {
        $limit = (int) ($limit ?: self::DEFAULT_LIMIT);

        return max(1, min($limit, self::MAX_LIMIT));
    }

    /**
     * Сортировка: по метрике (по умолчанию — по убыванию значения) или
     * по разбивке. Ось времени по умолчанию идёт по возрастанию — иначе
     * график читался бы справа налево.
     */
    private function orderFor(array $builder, ?array $dimension, ?string $valueAlias): ?string
    {
        $sort = is_array($builder['sort'] ?? null) ? $builder['sort'] : [];
        $by = $sort['by'] ?? null;

        if ($by === 'none') {
            return null;
        }

        if ($by === 'dimension' && $dimension) {
            return $dimension['expression'].' '.$this->direction($sort['dir'] ?? 'asc');
        }

        // Ось времени по умолчанию идёт по возрастанию: «топ месяцев по
        // выручке» — почти никогда не то, что имел в виду автор, а график,
        // читаемый справа налево, не читается вовсе.
        if ($by === null && $dimension && $this->isTimeDimension($dimension)) {
            return $dimension['expression'].' ASC';
        }

        if (!$valueAlias) {
            return $dimension ? $dimension['expression'].' ASC' : null;
        }

        return $this->alias($valueAlias).' '.$this->direction($sort['dir'] ?? 'desc');
    }

    /** Разбивка с округлением даты: выражение отличается от голой колонки. */
    private function isTimeDimension(array $dimension): bool
    {
        return $dimension['expression'] !== $this->quote($dimension['column']);
    }

    private function direction(mixed $dir): string
    {
        return strtolower((string) $dir) === 'asc' ? 'ASC' : 'DESC';
    }

    // -----------------------------------------------------------------
    // Диалект
    // -----------------------------------------------------------------

    /**
     * Собирает SELECT из уже проверенных частей.
     *
     * @param array<int, string> $columns
     * @param array<int, string> $where
     * @param array<int, string> $groupBy
     */
    private function select(
        array $columns,
        string $table,
        array $where,
        array $groupBy,
        ?string $orderBy,
        ?int $limit
    ): string {
        $sql = "SELECT\n    ".implode(",\n    ", $columns)."\nFROM ".$this->quote($table);

        if ($where !== []) {
            $sql .= "\nWHERE ".implode("\n  AND ", $where);
        }

        if ($groupBy !== []) {
            $sql .= "\nGROUP BY ".implode(', ', $groupBy);
        }

        if ($orderBy) {
            $sql .= "\nORDER BY ".$orderBy;
        }

        if ($limit !== null) {
            $sql .= "\nLIMIT ".$limit;
        }

        return $sql;
    }

    /**
     * Округление даты до периода. Строкой, а не датой: подпись оси должна
     * читаться человеком и сортироваться как текст в том же порядке, что
     * и время.
     */
    private function grainExpression(string $column, string $grain): string
    {
        return match ($this->driver) {
            'postgres' => match ($grain) {
                'day' => "TO_CHAR({$column}, 'YYYY-MM-DD')",
                'week' => "TO_CHAR(DATE_TRUNC('week', {$column}), 'IYYY-\"W\"IW')",
                'month' => "TO_CHAR({$column}, 'YYYY-MM')",
                'quarter' => "TO_CHAR({$column}, 'YYYY-\"Q\"Q')",
                default => "TO_CHAR({$column}, 'YYYY')",
            },

            'sqlite' => match ($grain) {
                'day' => "strftime('%Y-%m-%d', {$column})",
                'week' => "strftime('%Y-W%W', {$column})",
                'month' => "strftime('%Y-%m', {$column})",
                'quarter' => "strftime('%Y', {$column}) || '-Q' || ((CAST(strftime('%m', {$column}) AS INTEGER) + 2) / 3)",
                default => "strftime('%Y', {$column})",
            },

            'duckdb' => match ($grain) {
                'day' => "strftime({$column}, '%Y-%m-%d')",
                'week' => "strftime({$column}, '%Y-W%W')",
                'month' => "strftime({$column}, '%Y-%m')",
                'quarter' => "strftime({$column}, '%Y') || '-Q' || CAST(QUARTER({$column}) AS VARCHAR)",
                default => "strftime({$column}, '%Y')",
            },

            // MySQL и MariaDB
            default => match ($grain) {
                'day' => "DATE_FORMAT({$column}, '%Y-%m-%d')",
                'week' => "DATE_FORMAT({$column}, '%x-W%v')",
                'month' => "DATE_FORMAT({$column}, '%Y-%m')",
                'quarter' => "CONCAT(YEAR({$column}), '-Q', QUARTER({$column}))",
                default => "DATE_FORMAT({$column}, '%Y')",
            },
        };
    }

    private function requireColumn(string $table, mixed $column): string
    {
        $column = is_string($column) ? trim($column) : '';

        if ($column === '') {
            throw new RuntimeException('Не выбрана колонка.');
        }

        // Главная проверка конструктора: в запрос попадают только колонки,
        // которые действительно есть в таблице. Всё остальное — отказ,
        // а не попытка выполнить и посмотреть, что скажет база.
        if (!isset($this->schema[$table][$column])) {
            throw new RuntimeException("В таблице «{$table}» нет колонки «{$column}».");
        }

        return $column;
    }

    private function quote(string $identifier): string
    {
        return $this->driver === 'mysql'
            ? '`'.str_replace('`', '``', $identifier).'`'
            : '"'.str_replace('"', '""', $identifier).'"';
    }

    /** Псевдоним колонки результата. */
    private function alias(string $name): string
    {
        return $this->quote($name);
    }

    /**
     * Значение фильтра, приведённое к типу колонки.
     *
     * Приведение — не косметика: именно оно не даёт произвольному тексту
     * попасть в запрос. Не прошло приведение — запрос не собирается.
     */
    private function literal(mixed $value, string $kind): string
    {
        if ($kind === 'number') {
            if (!is_numeric($value)) {
                throw new RuntimeException("Значение «{$this->plain($value)}» не число.");
            }

            $type = str_contains((string) $value, '.')
                ? SqlParameterBinder::TYPE_FLOAT
                : SqlParameterBinder::TYPE_INT;
        } else {
            $type = $kind === 'date'
                ? SqlParameterBinder::TYPE_DATE
                : SqlParameterBinder::TYPE_STRING;
        }

        // Через apply(): плейсхолдер заменяется значением, прошедшим cast.
        // Не прошло приведение — дальше значение не идёт.
        $applied = $this->binder->apply(':v', ['v' => $value], ['v' => $type]);

        return $applied['sql'];
    }

    private function literalString(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function plain(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
