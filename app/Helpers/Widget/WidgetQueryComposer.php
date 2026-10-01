<?php

namespace App\Helpers\Widget;

use App\Helpers\DataSource\SourceSchema;
use App\Helpers\DataSource\SqlParameterBinder;
use App\Models\DataSource;
use RuntimeException;

class WidgetQueryComposer
{

    public const AGGREGATES = [
        'count' => 'Количество строк',
        'count_distinct' => 'Количество уникальных',
        'sum' => 'Сумма',
        'avg' => 'Среднее',
        'min' => 'Минимум',
        'max' => 'Максимум',
    ];

    private const AGGREGATES_WITHOUT_COLUMN = ['count'];

    private const NUMERIC_AGGREGATES = ['sum', 'avg'];

    public const GRAINS = [
        'day' => 'По дням',
        'week' => 'По неделям',
        'month' => 'По месяцам',
        'quarter' => 'По кварталам',
        'year' => 'По годам',
    ];

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

    private const OPERATORS_WITHOUT_VALUE = ['is_null', 'not_null'];

    public const JOIN_TYPES = [
        'left' => 'Все строки основной таблицы',
        'inner' => 'Только совпавшие строки',
        'right' => 'Все строки связанной таблицы',

        'cross' => 'Без условия — все строки со всеми',
    ];

    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 5000;

    private string $driver;
    private SqlParameterBinder $binder;

    private array $schema;

    private string $baseTable = '';

    private array $joins = [];

    private ?array $subquery = null;

    public function __construct(private DataSource $dataSource)
    {
        $this->driver = $dataSource->type->name ?? 'mysql';
        $this->schema = SourceSchema::map($dataSource);

        $this->binder = new SqlParameterBinder(supportsBindings: false);
    }

    public function compose(array $builder, string $family, ?string $type = null): array
    {

        $this->joins = [];
        $this->subquery = null;
        $this->schema = SourceSchema::map($this->dataSource);

        try {

            $subquery = $this->readSubquery($builder['subquery'] ?? null);

            $table = $subquery
                ? $this->registerSubquery($subquery)
                : $this->requireTable($builder['table'] ?? null);

            $this->joins = $this->readJoins($builder['joins'] ?? [], $table);
            $this->baseTable = $table;

            $shape = WidgetShapeMapper::shapeFor($family);

            $independent = in_array($shape, [
                WidgetShapeMapper::SHAPE_COUNTERS,
                WidgetShapeMapper::SHAPE_SERIES_VALUES,
            ], true) && empty($builder['dimensions']);

            $metrics = $this->readMetrics($builder['metrics'] ?? [], $table, $independent);
            $dimensions = $this->readDimensions($builder['dimensions'] ?? [], $table);
            $where = $this->readFilters($builder['filters'] ?? [], $table, $independent);
            $limit = $this->readLimit($builder['limit'] ?? null);

            if ($metrics === [] && $shape !== WidgetShapeMapper::SHAPE_ROWS) {
                throw new RuntimeException('Добавьте хотя бы одну метрику — иначе считать нечего.');
            }

            $sql = match ($shape) {
                WidgetShapeMapper::SHAPE_SERIES_MATRIX => $this->composeMatrix($table, $metrics, $dimensions, $this->conditionsFor($where, $this->tablesInPlay($table)), $builder, $limit),
                WidgetShapeMapper::SHAPE_SERIES_VALUES => $this->composeValues($table, $metrics, $dimensions, $where, $builder, $limit),
                WidgetShapeMapper::SHAPE_COUNTERS => $this->composeCounters($table, $metrics, $dimensions, $where, $builder, $limit, $type),
                WidgetShapeMapper::SHAPE_POINTS => $this->composePoints($table, $metrics, $dimensions, $this->conditionsFor($where, $this->tablesInPlay($table)), $builder, $limit, $type),
                WidgetShapeMapper::SHAPE_ROWS => $this->composeRows($table, $metrics, $dimensions, $this->conditionsFor($where, $this->tablesInPlay($table)), $builder, $limit),
                default => throw new RuntimeException("Для этого виджета конструктор пока не поддерживается."),
            };

            return [
                'ok' => true,
                'sql' => $sql,

                'presentation' => $this->presentationFor($family, $metrics),

                'columns' => [
                    'dimensions' => array_column($dimensions, 'label'),
                    'metrics' => array_column($metrics, 'label'),
                ],
                'errors' => [],
            ];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'sql' => null, 'presentation' => [], 'columns' => ['dimensions' => [], 'metrics' => []], 'errors' => [$e->getMessage()]];
        }
    }

    private function presentationFor(string $family, array $metrics): array
    {
        if ($family !== 'combo' || count($metrics) < 2) {
            return [];
        }

        $kinds = [];

        foreach ($metrics as $index => $metric) {

            $kinds[$metric['label']] = $index === 0 ? 'column' : 'line';
        }

        return ['series_kinds' => $kinds];
    }

    public static function slotsFor(string $family, ?string $type = null): array
    {

        if ($family === 'combo') {
            return [
                'dimensions' => ['min' => 1, 'max' => 1],
                'metrics' => ['min' => 2, 'max' => 10],
                'hint' => 'Разбивка станет осью. Первая метрика рисуется столбцами, '
                    . 'остальные — линиями.',
            ];
        }

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

        if ($breakdown && count($metrics) > 1) {
            throw new RuntimeException(
                'Вместе со второй разбивкой оставьте одну метрику: иначе рядов станет '
                . 'слишком много и график будет нечитаем.'
            );
        }

        if ($breakdown) {

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

                $this->conditionsFor($where, $this->tablesInPlay($table)),
                [$dimensions[0]['expression']],
                $this->orderFor($builder, $dimensions[0], 'value'),
                $limit
            );
        }

        return $this->unionOfMetrics($table, $metrics, $where, 'label', $limit);
    }

    private function composeCounters(
        string $table,
        array $metrics,
        array $dimensions,
        array $where,
        array $builder,
        int $limit,
        ?string $type = null
    ): string {

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

                $this->conditionsFor($where, $this->tablesInPlay($table)),
                [$dimensions[0]['expression']],
                $this->orderFor($builder, $dimensions[0], 'value'),
                $limit
            );
        }

        return $this->unionOfMetrics($table, $metrics, $where, 'name', $limit, $withProgress);
    }

    private function percentExpression(array $metric): string
    {
        $expression = $metric['expression'];

        if (!empty($metric['target'])) {
            $expression = 'ROUND(100 * '.$expression.' / '.$metric['target'].', 1)';
        }

        return 'LEAST(100, GREATEST(0, '.$expression.'))';
    }

    private function label(mixed $label, string $fallback): string
    {
        $label = trim((string) ($label ?? ''));

        if ($label === '') {
            return $fallback;
        }

        return mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1);
    }

    private function readTarget(mixed $target): ?float
    {
        if ($target === null || $target === '' || !is_numeric($target)) {
            return null;
        }

        $value = (float) $target;

        return $value > 0 ? $value : null;
    }

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

        return $this->select(
            $columns,
            $table,
            $where,
            $metrics === [] ? [] : $group,
            $this->orderFor($builder, $dimensions[0] ?? null, $metrics === [] ? null : $metrics[0]['label']),
            $limit
        );
    }

    private function unionOfMetrics(
        string $table,
        array $metrics,
        array $where,
        string $nameAlias,
        int $limit,
        bool $withProgress = false
    ): string {

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

            $metricTable = $metric['table'] ?? $table;
            $tables = $metricTable === $table
                ? $this->tablesInPlay($table)
                : [$metricTable];

            $parts[] = $this->select(
                $columns,
                $metricTable,
                $this->conditionsFor($where, $tables),
                [],
                null,
                null
            );
        }

        return implode("\nUNION ALL\n", $parts)."\nLIMIT ".$limit;
    }

    private function readJoins(mixed $joins, string $baseTable): array
    {
        if (!is_array($joins) || $joins === []) {
            return [];
        }

        $result = [];

        $available = [$baseTable];

        foreach ($joins as $join) {
            if (!is_array($join)) {
                continue;
            }

            $table = $this->requireTable($join['table'] ?? null);

            if (in_array($table, $available, true)) {
                throw new RuntimeException("Таблица «{$table}» уже участвует в запросе.");
            }

            $type = strtolower(trim((string) ($join['type'] ?? 'left')));

            if (!array_key_exists($type, self::JOIN_TYPES)) {
                throw new RuntimeException("Неизвестный тип связи «{$type}».");
            }

            $conditions = [];

            foreach (($join['on'] ?? []) as $pair) {
                if (!is_array($pair)) {
                    continue;
                }

                $leftTable = trim((string) ($pair['left_table'] ?? $baseTable));

                if (!in_array($leftTable, $available, true)) {
                    throw new RuntimeException(
                        "Связь ссылается на таблицу «{$leftTable}», которой ещё нет в запросе."
                    );
                }

                $conditions[] = [
                    'left_table' => $leftTable,
                    'left' => $this->requireColumn($leftTable, $pair['left'] ?? null),
                    'right' => $this->requireColumn($table, $pair['right'] ?? null),
                ];
            }

            if ($conditions === [] && $type !== 'cross') {
                throw new RuntimeException(
                    "Для таблицы «{$table}» не указано, по каким колонкам её связывать. "
                    . "Если связывать нечем, выберите тип «без условия»."
                );
            }

            $result[] = ['table' => $table, 'type' => $type, 'on' => $conditions];
            $available[] = $table;
        }

        return $result;
    }

    private function tablesInPlay(string $baseTable): array
    {
        return array_merge([$baseTable], array_column($this->joins, 'table'));
    }

    private function readSubquery(mixed $subquery): ?array
    {
        if (!is_array($subquery)) {
            return null;
        }

        $sql = trim((string) ($subquery['query'] ?? ''));

        if ($sql === '') {
            return null;
        }

        $columns = [];

        foreach (($subquery['columns'] ?? []) as $column) {
            if (is_array($column) && !empty($column['name'])) {
                $columns[(string) $column['name']] = SourceSchema::kindOf($column['type'] ?? null);
            } elseif (is_string($column) && $column !== '') {
                $columns[$column] = 'string';
            }
        }

        if ($columns === []) {
            throw new RuntimeException(
                'Неизвестно, какие колонки возвращает запрос-источник. Выполните его один раз.'
            );
        }

        return ['sql' => $sql, 'columns' => $columns, 'alias' => 'source'];
    }

    private function registerSubquery(array $subquery): string
    {
        $alias = $subquery['alias'];

        $this->schema[$alias] = $subquery['columns'];
        $this->subquery = $subquery;

        return $alias;
    }

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

    private function readMetrics(mixed $metrics, string $table, bool $independentTables = false): array
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

            $metricTable = $this->metricTable($metric, $table, $independentTables);

            if (in_array($agg, self::AGGREGATES_WITHOUT_COLUMN, true)) {
                $result[] = [
                    'expression' => 'COUNT(*)',
                    'label' => $this->label($metric['label'] ?? null, 'Количество'),
                    'target' => $this->readTarget($metric['target'] ?? null),
                    'table' => $metricTable,
                ];

                continue;
            }

            $resolved = $this->resolveColumn($metric, $metricTable, $independentTables);
            $column = $resolved['column'];

            if (in_array($agg, self::NUMERIC_AGGREGATES, true) && $resolved['kind'] !== 'number') {
                throw new RuntimeException(
                    "Колонка «{$column}» не числовая — посчитать по ней «".self::AGGREGATES[$agg]."» нельзя."
                );
            }

            $reference = $this->columnRef($resolved);

            $expression = match ($agg) {
                'count_distinct' => 'COUNT(DISTINCT '.$reference.')',
                default => strtoupper($agg).'('.$reference.')',
            };

            $result[] = [
                'expression' => $expression,
                'label' => $this->label($metric['label'] ?? null, self::AGGREGATES[$agg].' '.$column),

                'target' => $this->readTarget($metric['target'] ?? null),
                'table' => $resolved['table'],
            ];
        }

        if ($result === []) {
            return [];
        }

        return $result;
    }

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

            $resolved = $this->resolveColumn($dimension, $table);
            $column = $resolved['column'];
            $grain = $dimension['grain'] ?? null;

            $expression = $this->columnRef($resolved);

            if ($grain) {
                if (!array_key_exists($grain, self::GRAINS)) {
                    throw new RuntimeException("Неизвестное округление даты «{$grain}».");
                }

                if ($resolved['kind'] !== 'date') {
                    throw new RuntimeException("Колонка «{$column}» не дата — округлять её по периодам нельзя.");
                }

                $expression = $this->grainExpression($expression, $grain);
            }

            $result[] = [
                'expression' => $expression,
                'label' => trim((string) ($dimension['label'] ?? '')) ?: $column,
                'column' => $column,

                'reference' => $this->columnRef($resolved),
            ];
        }

        return $result;
    }

    private function readFilters(mixed $filters, string $table, bool $independentTables = false): array
    {
        if (!is_array($filters)) {
            return [];
        }

        $conditions = [];

        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            $resolved = $this->resolveColumn($filter, $table, $independentTables);
            $column = $resolved['column'];
            $op = trim((string) ($filter['op'] ?? '='));

            if (!array_key_exists($op, self::OPERATORS)) {
                throw new RuntimeException("Неизвестное условие «{$op}».");
            }

            $quoted = $this->columnRef($resolved);
            $kind = $resolved['kind'];

            if (in_array($op, self::OPERATORS_WITHOUT_VALUE, true)) {
                $conditions[] = [
                    'table' => $resolved['table'],
                    'sql' => $op === 'is_null' ? "{$quoted} IS NULL" : "{$quoted} IS NOT NULL",
                ];

                continue;
            }

            $value = $filter['value'] ?? null;

            if ($value === null || $value === '' || $value === []) {
                throw new RuntimeException("У условия по колонке «{$column}» не задано значение.");
            }

            $conditions[] = ['table' => $resolved['table'], 'sql' => match ($op) {
                'in' => $quoted.' IN ('.implode(', ', array_map(
                    fn ($item) => $this->literal($item, $kind),
                    is_array($value) ? $value : preg_split('/\s*,\s*/', (string) $value)
                )).')',

                'contains' => $quoted.' LIKE '.$this->literal('%'.$this->plain($value).'%', 'string'),

                'starts_with' => $quoted.' LIKE '.$this->literal($this->plain($value).'%', 'string'),

                'between' => $this->betweenCondition($quoted, $value, $kind, $column),

                default => $quoted.' '.$op.' '.$this->literal($value, $kind),
            }];
        }

        return $conditions;
    }

    private function conditionsFor(array $conditions, array $tables): array
    {
        $result = [];

        foreach ($conditions as $condition) {
            if (in_array($condition['table'], $tables, true)) {
                $result[] = $condition['sql'];
            }
        }

        return $result;
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

        if ($by === null && $dimension && $this->isTimeDimension($dimension)) {
            return $dimension['expression'].' ASC';
        }

        if (!$valueAlias) {
            return $dimension ? $dimension['expression'].' ASC' : null;
        }

        return $this->alias($valueAlias).' '.$this->direction($sort['dir'] ?? 'desc');
    }

    private function isTimeDimension(array $dimension): bool
    {
        return $dimension['expression'] !== ($dimension['reference'] ?? $this->quote($dimension['column']));
    }

    private function direction(mixed $dir): string
    {
        return strtolower((string) $dir) === 'asc' ? 'ASC' : 'DESC';
    }

    private function select(
        array $columns,
        string $table,
        array $where,
        array $groupBy,
        ?string $orderBy,
        ?int $limit
    ): string {

        $joins = $table === ($this->baseTable ?: $table) ? $this->joinsSql() : '';

        $sql = "SELECT\n    ".implode(",\n    ", $columns)."\nFROM ".$this->fromSql($table).$joins;

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

    private function fromSql(string $table): string
    {
        if ($this->subquery && $this->subquery['alias'] === $table) {
            $inner = rtrim(trim($this->subquery['sql']), "; \t\n\r");

            return "(\n".$inner."\n) AS ".$this->quote($table);
        }

        return $this->quote($table);
    }

    private function joinsSql(): string
    {
        $sql = '';

        foreach ($this->joins as $join) {
            $conditions = [];

            foreach ($join['on'] as $pair) {
                $conditions[] = $this->quote($pair['left_table']).'.'.$this->quote($pair['left'])
                    .' = '.$this->quote($join['table']).'.'.$this->quote($pair['right']);
            }

            $sql .= "\n".strtoupper($join['type']).' JOIN '.$this->quote($join['table']);

            if ($conditions !== []) {
                $sql .= ' ON '.implode(' AND ', $conditions);
            }
        }

        return $sql;
    }

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

        if (!isset($this->schema[$table][$column])) {
            throw new RuntimeException("В таблице «{$table}» нет колонки «{$column}».");
        }

        return $column;
    }

    private function metricTable(array $metric, string $fallback, bool $independentTables): string
    {
        $table = trim((string) ($metric['table'] ?? '')) ?: $fallback;

        if ($table === $fallback) {
            return $fallback;
        }

        $this->requireTable($table);

        if (!$independentTables && !in_array($table, $this->tablesInPlay($fallback), true)) {
            throw new RuntimeException(
                "Таблица «{$table}» не участвует в запросе — добавьте её связью."
            );
        }

        return $table;
    }

    private function resolveColumn(array $definition, string $fallbackTable, bool $independentTables = false): array
    {
        $column = is_string($definition['column'] ?? null) ? trim($definition['column']) : '';

        if ($column === '') {
            throw new RuntimeException('Не выбрана колонка.');
        }

        $available = $this->tablesInPlay($this->baseTable ?: $fallbackTable);
        $declared = trim((string) ($definition['table'] ?? ''));

        if ($declared !== '') {
            $this->requireTable($declared);

            if (!in_array($declared, $available, true)
                && !($independentTables && array_key_exists($declared, $this->schema))
            ) {
                throw new RuntimeException(
                    "Таблица «{$declared}» не участвует в запросе — добавьте её связью."
                );
            }

            return [
                'table' => $declared,
                'column' => $this->requireColumn($declared, $column),
                'kind' => $this->schema[$declared][$column],
            ];
        }

        $found = [];

        foreach ($available as $candidate) {
            if (isset($this->schema[$candidate][$column])) {
                $found[] = $candidate;
            }
        }

        if (count($found) === 1) {
            return [
                'table' => $found[0],
                'column' => $column,
                'kind' => $this->schema[$found[0]][$column],
            ];
        }

        if (count($found) > 1) {
            throw new RuntimeException(sprintf(
                'Колонка «%s» есть в нескольких таблицах (%s) — уточните, из какой она.',
                $column,
                implode(', ', $found)
            ));
        }

        throw new RuntimeException(sprintf(
            'Колонки «%s» нет ни в одной из таблиц запроса (%s).',
            $column,
            implode(', ', $available)
        ));
    }

    private function columnRef(array $resolved): string
    {
        return $this->joins === []
            ? $this->quote($resolved['column'])
            : $this->quote($resolved['table']).'.'.$this->quote($resolved['column']);
    }

    private function quote(string $identifier): string
    {
        return $this->driver === 'mysql'
            ? '`'.str_replace('`', '``', $identifier).'`'
            : '"'.str_replace('"', '""', $identifier).'"';
    }

    private function alias(string $name): string
    {
        return $this->quote($name);
    }

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

        $cast = $this->binder->cast($value, $type);

        if ($cast === null) {
            return 'NULL';
        }

        if (is_int($cast) || is_float($cast)) {
            return (string) $cast;
        }

        return $this->literalString((string) $cast);
    }

    private function literalString(string $value): string
    {
        if ($this->driver === 'mysql') {
            $value = str_replace('\\', '\\\\', $value);
        }

        return "'".str_replace("'", "''", $value)."'";
    }

    private function plain(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
