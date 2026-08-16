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

    /**
     * Как связывать таблицы.
     *
     * По умолчанию LEFT: аналитик почти всегда хочет видеть все строки
     * основной таблицы, даже если в связанной пары не нашлось. INNER
     * молча выбрасывал бы их, и цифры расходились бы с ожиданием.
     */
    public const JOIN_TYPES = [
        'left' => 'Все строки основной таблицы',
        'inner' => 'Только совпавшие строки',
        'right' => 'Все строки связанной таблицы',
        // Таблицы, которые нечем связать. Строки перемножаются, поэтому
        // выбор осознанный: это не «на всякий случай», а «мне правда нужно
        // каждое с каждым».
        'cross' => 'Без условия — все строки со всеми',
    ];

    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 5000;

    private string $driver;
    private SqlParameterBinder $binder;

    /** @var array<string, array<string, string>> таблица => колонка => вид */
    private array $schema;

    /** Основная таблица текущего запроса. */
    private string $baseTable = '';

    /** @var array<int, array{table: string, type: string, on: array}> Связи текущего запроса */
    private array $joins = [];

    /** @var array{sql: string, columns: array, alias: string}|null Источник-запрос */
    private ?array $subquery = null;

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
        // Состояние сбрасывается на каждый вызов: один объект собирает
        // много запросов подряд, и остатки прошлого не должны в них попадать.
        $this->joins = [];
        $this->subquery = null;
        $this->schema = SourceSchema::map($this->dataSource);

        try {
            // Источником может быть не только таблица, но и запрос: так
            // конструктор работает поверх выборки, которую иначе пришлось бы
            // писать целиком руками. Это ответ на подзапросы — в Superset
            // ту же роль играет виртуальный датасет, в Metabase — вопрос,
            // построенный на другом вопросе.
            $subquery = $this->readSubquery($builder['subquery'] ?? null);

            $table = $subquery
                ? $this->registerSubquery($subquery)
                : $this->requireTable($builder['table'] ?? null);

            // Связи разбираются первыми: от них зависит, какие таблицы вообще
            // доступны метрикам, разбивкам и условиям.
            $this->joins = $this->readJoins($builder['joins'] ?? [], $table);
            $this->baseTable = $table;

            $shape = WidgetShapeMapper::shapeFor($family);

            // Счётчики и плоские списки без разбивки считают каждую метрику
            // отдельной выборкой — значит таблицы могут быть любые, в том
            // числе никак не связанные между собой.
            $independent = in_array($shape, [
                WidgetShapeMapper::SHAPE_COUNTERS,
                WidgetShapeMapper::SHAPE_SERIES_VALUES,
            ], true) && empty($builder['dimensions']);

            $metrics = $this->readMetrics($builder['metrics'] ?? [], $table, $independent);
            $dimensions = $this->readDimensions($builder['dimensions'] ?? [], $table);
            $where = $this->readFilters($builder['filters'] ?? [], $table, $independent);
            $limit = $this->readLimit($builder['limit'] ?? null);

            // Без метрик считать нечего. Проверка нужна именно здесь: раньше
            // пустой список молча доходил до сборки, и получался обрубок вида
            // «SELECT country AS label, AS value» — база отвечала на него
            // невнятной ошибкой синтаксиса вместо понятной подсказки.
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
                // С разбивкой это одна выборка, а не объединение метрик:
                // условия нужны текстом. Массив пар «таблица + условие»
                // доходил сюда как есть и превращался в «WHERE Array».
                $this->conditionsFor($where, $this->tablesInPlay($table)),
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
                // См. composeValues(): с разбивкой запрос один, и условия
                // нужны строками, а не парами «таблица + условие».
                $this->conditionsFor($where, $this->tablesInPlay($table)),
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
     * Подпись метрики.
     *
     * Первая буква поднимается здесь, а не выпрашивается у модели: подпись
     * едет в легенду и на плитки, и «количество клиентов» рядом с «Заказы»
     * выглядит небрежно. Просить об этом промптом — значит зависеть от
     * настроения модели на каждом виджете.
     */
    private function label(mixed $label, string $fallback): string
    {
        $label = trim((string) ($label ?? ''));

        if ($label === '') {
            return $fallback;
        }

        return mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1);
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

            // Каждая метрика считается по СВОЕЙ таблице. Именно это делает
            // возможным счётчик «Заказов / Клиентов / Товаров»: три числа
            // из трёх таблиц, которые нечем и незачем связывать.
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

    // -----------------------------------------------------------------
    // Разбор настроек
    // -----------------------------------------------------------------

    /**
     * Разбирает связи между таблицами.
     *
     * Условие связи — пара колонок, и обе проверяются по схеме: в запрос
     * не попадает ничего, чего нет в источнике. Без этого join был бы
     * дырой ровно того сорта, от которой конструктор и уходит.
     *
     * @return array<int, array{table: string, type: string, on: array<int, array{left_table: string, left: string, right: string}>}>
     */
    private function readJoins(mixed $joins, string $baseTable): array
    {
        if (!is_array($joins) || $joins === []) {
            return [];
        }

        $result = [];
        // Таблицы, к которым уже можно присоединяться: основная и всё,
        // что присоединено до этого шага.
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

                // Слева — колонка одной из уже подключённых таблиц,
                // справа — колонка присоединяемой.
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

            // Связь без условия — единственный случай, когда пары колонок нет.
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

    /**
     * Таблицы, доступные метрикам, разбивкам и условиям.
     *
     * @return array<int, string>
     */
    private function tablesInPlay(string $baseTable): array
    {
        return array_merge([$baseTable], array_column($this->joins, 'table'));
    }

    /**
     * Разбирает подзапрос-источник.
     */
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

    /**
     * Регистрирует подзапрос как таблицу: дальше конструктор работает с ним
     * так же, как с обычной, — те же метрики, разбивки и условия.
     */
    private function registerSubquery(array $subquery): string
    {
        $alias = $subquery['alias'];

        // Колонки подзапроса проверяются так же строго, как колонки таблицы:
        // белый список — то, что он реально вернул при проверке.
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

    /**
     * @param bool $independentTables Разрешено ли метрике брать таблицу, не
     *        связанную с основной. Так устроены счётчики и плоские списки:
     *        каждая метрика там — отдельная выборка, и связывать нечего.
     *
     * @return array<int, array{expression: string, label: string, table: string}>
     */
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

            // Таблица метрики: своя или основная. Раньше она у COUNT(*)
            // просто терялась, и «клиентов» считалось по таблице заказов —
            // молча и неверно.
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
                // Цель нужна счётчику с полосой выполнения: от неё считается
                // процент. У остальных виджетов поле просто не используется.
                'target' => $this->readTarget($metric['target'] ?? null),
                'table' => $resolved['table'],
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
                // Ссылка без округления — по ней видно, что разбивка временная.
                'reference' => $this->columnRef($resolved),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array{table: string, sql: string}> Условия с их таблицами
     */
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

    /**
     * Условия, относящиеся к этой таблице и связанным с ней.
     *
     * В объединении метрик у каждой части свой источник, и чужое условие
     * туда просто не подставить: колонки в этой выборке нет.
     *
     * @param array<int, array{table: string, sql: string}> $conditions
     *
     * @return array<int, string>
     */
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
        return $dimension['expression'] !== ($dimension['reference'] ?? $this->quote($dimension['column']));
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
        // Связи относятся к основной таблице: у отдельной выборки метрики
        // их быть не должно, иначе в неё попали бы чужие соединения.
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

    /**
     * Источник запроса: таблица или подзапрос.
     */
    private function fromSql(string $table): string
    {
        if ($this->subquery && $this->subquery['alias'] === $table) {
            $inner = rtrim(trim($this->subquery['sql']), "; \t\n\r");

            return "(\n".$inner."\n) AS ".$this->quote($table);
        }

        return $this->quote($table);
    }

    /**
     * Связи для FROM.
     */
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

            // У связи без условия ON не бывает: пустой ON — синтаксическая
            // ошибка, а не «соединить всё со всем».
            if ($conditions !== []) {
                $sql .= ' ON '.implode(' AND ', $conditions);
            }
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

    /**
     * Таблица метрики.
     */
    private function metricTable(array $metric, string $fallback, bool $independentTables): string
    {
        $table = trim((string) ($metric['table'] ?? '')) ?: $fallback;

        if ($table === $fallback) {
            return $fallback;
        }

        $this->requireTable($table);

        // В формах с одной выборкой чужая таблица без связи дала бы
        // перемножение строк и неверные числа.
        if (!$independentTables && !in_array($table, $this->tablesInPlay($fallback), true)) {
            throw new RuntimeException(
                "Таблица «{$table}» не участвует в запросе — добавьте её связью."
            );
        }

        return $table;
    }

    /**
     * Разбирает ссылку на колонку: своя таблица у неё или основная.
     *
     * Со связями одного имени мало: «orderNumber» есть и в заказах, и в их
     * позициях. Поэтому колонка может назвать свою таблицу, а если не назвала —
     * берётся основная, и виджеты, собранные до появления связей, работают
     * как работали.
     *
     * @return array{table: string, column: string, kind: string}
     */
    private function resolveColumn(array $definition, string $fallbackTable, bool $independentTables = false): array
    {
        $column = is_string($definition['column'] ?? null) ? trim($definition['column']) : '';

        if ($column === '') {
            throw new RuntimeException('Не выбрана колонка.');
        }

        $available = $this->tablesInPlay($this->baseTable ?: $fallbackTable);
        $declared = trim((string) ($definition['table'] ?? ''));

        // Таблица названа явно — работаем с ней и только с ней. Подменять её
        // другой, где случайно есть колонка с тем же именем, нельзя: автор
        // получил бы молча не те числа.
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

        // Таблица не названа или названа неверно — ищем колонку среди тех,
        // что участвуют в запросе. Так конструктор перестал спотыкаться на
        // виджетах, собранных до появления связей: там у колонок таблицы нет
        // вовсе, и раньше они все искались в основной.
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

        // Одноимённые колонки в разных таблицах: гадать нельзя — какую
        // из них имели в виду, знает только автор.
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

    /**
     * Имя колонки в запросе.
     *
     * Пока таблица одна, префикс не нужен и только зашумляет запрос. Как
     * только появились связи, он обязателен: без него база не знает, чей
     * это «orderNumber».
     */
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

        // Приведение к типу — первая половина защиты: не прошло, значит
        // дальше значение не идёт. Вторая половина — экранирование, и оно
        // зависит от диалекта, поэтому делается здесь, а не в биндере.
        $cast = $this->binder->cast($value, $type);

        if ($cast === null) {
            return 'NULL';
        }

        if (is_int($cast) || is_float($cast)) {
            return (string) $cast;
        }

        return $this->literalString((string) $cast);
    }

    /**
     * Строковый литерал запроса.
     *
     * Одинарная кавычка удваивается — это стандарт. Обратный слэш отдельно:
     * MySQL и MariaDB по умолчанию считают его экранирующим символом, и одной
     * удвоенной кавычки там мало. Значение, оканчивающееся на «\», закрывало бы
     * строку раньше времени, и всё, что идёт следом, читалось бы как часть
     * запроса — в подписи метрики и в значении условия это открытая дверь.
     */
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
