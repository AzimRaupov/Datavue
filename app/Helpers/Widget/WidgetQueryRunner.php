<?php

namespace App\Helpers\Widget;

use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Helpers\DataSource\ReadOnlySqlGuard;
use App\Helpers\DataSource\SqlParameterBinder;
use App\Models\DataSource;
use RuntimeException;
use Throwable;

class WidgetQueryRunner
{

    /**
     * Потолок строк для превью и фильтра «limit».
     */
    public const MAX_ROWS = 50;

    /**
     * Сколько строк виджет получает целиком. Раньше здесь стояло MAX_ROWS:
     * ряды графика — это ячейки «ряд × категория», и срез на 50 ячейках
     * обрывал ось посреди категории, а недостающие значения становились
     * нулями. Теперь график получает данные целиком (потолок — тот же,
     * что у конструктора), а читаемость обеспечивает фронт: окно по оси
     * с переключением страниц и «Прочее» у круговых.
     */
    public const MAX_FETCH_ROWS = 5000;

    public const DEFAULT_PER_PAGE = 25;
    public const MAX_PER_PAGE = 50;

    public const SAMPLE_ROWS = 20;

    private SqlParameterBinder $binder;

    public function __construct(
        private DataSource $dataSource,
        private ?ConnectionProviderRouter $router = null
    ) {
        $this->router ??= new ConnectionProviderRouter($this->dataSource->id);

        $this->binder = new SqlParameterBinder(
            supportsBindings: ($this->dataSource->type->name ?? null) !== 'duckdb'
        );
    }

    public function run(
        array $spec,
        string $family,
        ?string $type = null,
        array $filters = [],
        array $input = [],
        bool $sample = false
    ): array {
        $shape = $spec['shape'] ?? null;

        if (!in_array($shape, WidgetShapeMapper::SHAPES, true)) {
            return ['ok' => false, 'error' => 'Неизвестная форма данных: '.json_encode($shape)];
        }

        $queries = $this->normalizeQueries($spec['queries'] ?? null);

        if ($queries === []) {
            return ['ok' => false, 'error' => 'В спецификации нет ни одного запроса.'];
        }

        $truncated = false;

        try {

            $multi = count($queries) > 1;

            if ($multi) {
                $rows = [];

                foreach ($queries as $name => $sql) {
                    $prepared = $this->prepare($sql, $filters, $input, stripLimit: false);

                    $fetched = $this->fetch($prepared, $this->chartMeta());

                    $truncated = $truncated || $fetched['truncated'];

                    foreach ($fetched['rows'] as $row) {
                        $rows[] = $row;
                    }
                }

                $meta = $this->chartMeta();
            } else {
                $prepared = $this->prepare(reset($queries), $filters, $input);

                $meta = $sample
                    ? $this->sampleMeta()
                    : $this->buildMeta($prepared, $filters, $input);

                $fetched = $this->fetch($prepared, $meta);
                $rows = $fetched['rows'];
                $truncated = $fetched['truncated'];
            }
        } catch (Throwable $e) {

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if ($truncated && $shape === WidgetShapeMapper::SHAPE_SERIES_MATRIX) {
            $rows = $this->withoutIncompleteTail($rows);
        }

        $meta['truncated'] = $truncated;
        $meta['max_rows'] = self::MAX_FETCH_ROWS;

        try {
            $data = (new WidgetShapeMapper())->map(
                family: $family,
                type: $type,
                shape: $shape,
                rows: $rows,
                presentation: $spec['presentation'] ?? []
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Не удалось разложить результат: '.$e->getMessage()];
        }

        return ['ok' => true, 'data' => $data, 'meta' => $meta];
    }

    private function normalizeQueries(mixed $queries): array
    {
        if (is_string($queries) && trim($queries) !== '') {
            return ['main' => $queries];
        }

        if (!is_array($queries)) {
            return [];
        }

        $result = [];

        foreach ($queries as $name => $sql) {
            if (is_string($sql) && trim($sql) !== '') {
                $result[(string) $name] = $sql;
            }
        }

        if (isset($result['main'])) {
            $result = ['main' => $result['main']] + $result;
        }

        return $result;
    }

    private function prepare(string $sql, array $filters, array $input, bool $stripLimit = true): array
    {
        $values = [];
        $types = [];

        foreach ($filters as $key => $config) {
            match ($key) {
                'date_range' => [
                    $values['date_from'] = $input['date_from'] ?? ($config['date_from'] ?? null),
                    $values['date_to'] = $input['date_to'] ?? ($config['date_to'] ?? null),
                    $types['date_from'] = SqlParameterBinder::TYPE_DATE,
                    $types['date_to'] = SqlParameterBinder::TYPE_DATE,
                ],
                'day' => [
                    $values['day'] = $input['day'] ?? ($config['day'] ?? null),
                    $types['day'] = SqlParameterBinder::TYPE_DATE,
                ],
                default => null,
            };
        }

        foreach ($this->placeholderNames($sql) as $name) {
            if (!array_key_exists($name, $values)) {
                $values[$name] = null;
            }
        }

        $applied = $this->binder->apply($sql, $values, $types);

        $clean = ReadOnlySqlGuard::sanitize($applied['sql'], null);

        if ($stripLimit && array_key_exists('paginate', $filters)) {
            $clean = ReadOnlySqlGuard::stripTrailingLimit($clean);
        }

        return ['sql' => $clean, 'bindings' => $applied['bindings']];
    }

    private function buildMeta(array $prepared, array $filters, array $input): array
    {
        $paginated = array_key_exists('paginate', $filters);
        $search = array_key_exists('search', $filters)
            ? trim((string) ($input['search'] ?? ''))
            : '';

        $perPage = (int) ($input['per_page'] ?? $filters['paginate']['per_page'] ?? self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $page = max(1, (int) ($input['page'] ?? 1));

        $limit = null;

        if (array_key_exists('limit', $filters)) {
            $limit = (int) ($input['limit'] ?? $filters['limit']['default'] ?? 10);
            $limit = max(1, min($limit, self::MAX_ROWS));
        }

        $sortBy = $paginated ? trim((string) ($input['sort_by'] ?? '')) : '';

        // Колонки читаем один раз: они нужны и поиску, и сортировке. Имя из
        // запроса принимаем только если оно есть в результате — произвольную
        // строку в ORDER BY не пускаем.
        $columns = $search !== '' || $sortBy !== '' ? $this->searchColumns($prepared) : [];

        $searchColumns = $search !== '' ? $columns : [];

        $sort = $sortBy !== '' && in_array($sortBy, $columns, true)
            ? [
                'by' => $sortBy,
                'dir' => strtolower((string) ($input['sort_dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
            ]
            : null;

        $total = $paginated
            ? $this->countRows($prepared, $search, $searchColumns)
            : null;

        $pages = $paginated ? max(1, (int) ceil($total / $perPage)) : null;

        // Страница за последней (строк стало меньше, пока пользователь листал)
        // — показываем последнюю, а не пустую таблицу.
        if ($pages !== null) {
            $page = min($page, $pages);
        }

        return [
            'paginated' => $paginated,
            'limit' => $limit,
            'search_columns' => $searchColumns,
            'sort' => $sort,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
            'truncated' => false,
            'search' => $search !== '' ? $search : null,
        ];
    }

    private function sampleMeta(?int $limit = null): array
    {
        return [
            'paginated' => false,
            'limit' => $limit ?? self::SAMPLE_ROWS,
            'search_columns' => [],
            'sort' => null,
            'total' => null,
            'page' => 1,
            'per_page' => self::SAMPLE_ROWS,
            'pages' => null,
            'search' => null,
            'truncated' => false,
        ];
    }

    /**
     * Без лимита: fetch() сам берёт MAX_FETCH_ROWS + 1 и по лишней строке
     * понимает, что данные обрезаны.
     */
    private function chartMeta(): array
    {
        return ['limit' => null] + $this->sampleMeta();
    }

    private function countRows(array $prepared, string $search, array $searchColumns): int
    {
        $wrapped = $this->wrap($prepared, $search, $searchColumns, null, null);

        $sql = 'SELECT COUNT(*) AS total FROM ('.$wrapped['sql'].') AS widget_count';

        $rows = ReadOnlySqlGuard::normalizeRows($this->router->query($sql, $wrapped['bindings']));

        return (int) ($rows[0]['total'] ?? 0);
    }

    private function fetch(array $prepared, array $meta): array
    {
        $limit = null;
        $offset = null;

        if ($meta['paginated']) {
            $limit = $meta['per_page'];
            $offset = ($meta['page'] - 1) * $meta['per_page'];
        } else {

            $limit = $meta['limit'] ?? (self::MAX_FETCH_ROWS + 1);
        }

        $wrapped = $this->wrap(
            $prepared,
            $meta['search'] ?? '',
            $meta['search_columns'] ?? [],
            $limit,
            $offset,
            $meta['sort'] ?? null,
            keepOrder: (bool) $meta['paginated']
        );

        $rows = ReadOnlySqlGuard::normalizeRows(
            $this->router->query($wrapped['sql'], $wrapped['bindings'])
        );

        $truncated = false;

        if (!$meta['paginated'] && $meta['limit'] === null && count($rows) > self::MAX_FETCH_ROWS) {
            $truncated = true;
            $rows = array_slice($rows, 0, self::MAX_FETCH_ROWS);
        }

        return ['rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * Обрезанный по потолку набор оканчивается посреди категории: у неё
     * есть не все ряды, и недостающие превратились бы в нули. Целая
     * категория лучше, чем категория с неверными значениями.
     */
    private function withoutIncompleteTail(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $last = $rows[array_key_last($rows)]['category'] ?? null;

        while ($rows !== [] && ($rows[array_key_last($rows)]['category'] ?? null) === $last) {
            array_pop($rows);
        }

        return $rows;
    }

    private function wrap(
        array $prepared,
        string $search,
        array $searchColumns,
        ?int $limit,
        ?int $offset,
        ?array $sort = null,
        bool $keepOrder = false
    ): array {
        $bindings = $prepared['bindings'];

        $hasSearch = $search !== '' && $searchColumns !== [];

        // Постраничный вывод без поиска и сортировки лимитируем прямо в
        // исходном запросе: подзапрос без LIMIT база вправе отдать в любом
        // порядке (MySQL выбрасывает его ORDER BY), и страницы поплыли бы.
        if ($keepOrder && $limit !== null && !$hasSearch && $sort === null) {
            $sql = $prepared['sql'].' LIMIT '.(int) $limit;

            if ($offset) {
                $sql .= ' OFFSET '.(int) $offset;
            }

            return ['sql' => $sql, 'bindings' => $bindings];
        }

        $sql = 'SELECT * FROM ('.$prepared['sql'].') AS widget_base';

        if ($hasSearch) {
            $conditions = [];

            $applied = $this->binder->apply(
                ':widget_search',
                ['widget_search' => '%'.$search.'%'],
                ['widget_search' => SqlParameterBinder::TYPE_STRING]
            );

            foreach ($searchColumns as $column) {
                $conditions[] = $this->castToText($this->quote($column)).' LIKE '.$applied['sql'];

                foreach ($applied['bindings'] as $binding) {
                    $bindings[] = $binding;
                }
            }

            $sql .= ' WHERE '.implode(' OR ', $conditions);
        }

        if ($sort !== null) {
            $sql .= ' ORDER BY '.$this->quote($sort['by']).' '.($sort['dir'] === 'desc' ? 'DESC' : 'ASC');
        }

        if ($limit !== null) {
            $sql .= ' LIMIT '.(int) $limit;

            if ($offset) {
                $sql .= ' OFFSET '.(int) $offset;
            }
        }

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    private function searchColumns(array $prepared): array
    {
        try {
            $probe = 'SELECT * FROM ('.$prepared['sql'].') AS widget_probe LIMIT 1';

            $rows = ReadOnlySqlGuard::normalizeRows(
                $this->router->query($probe, $prepared['bindings'])
            );

            return $rows === [] ? [] : array_keys($rows[0]);
        } catch (Throwable) {
            return [];
        }
    }

    private function placeholderNames(string $sql): array
    {
        preg_match_all('/(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function nullPlaceholders(string $sql): array
    {
        return array_fill_keys($this->placeholderNames($sql), null);
    }

    private function castToText(string $expression): string
    {
        return match ($this->dataSource->type->name ?? null) {
            'mysql' => "CAST({$expression} AS CHAR)",
            'postgres' => "{$expression}::text",
            default => "CAST({$expression} AS VARCHAR)",
        };
    }

    private function quote(string $identifier): string
    {
        $type = $this->dataSource->type->name ?? null;

        return $type === 'mysql'
            ? '`'.str_replace('`', '``', $identifier).'`'
            : '"'.str_replace('"', '""', $identifier).'"';
    }

    public function execute(string $sql, array $bindings = []): array
    {
        $safe = ReadOnlySqlGuard::sanitize($sql, self::MAX_ROWS);

        return ReadOnlySqlGuard::normalizeRows($this->router->query($safe, $bindings));
    }

    public function probe(string $sql, array $probeValues = []): array
    {
        try {

            $probeValues += $this->nullPlaceholders($sql);

            $applied = $this->binder->apply($sql, $probeValues, []);

            $safe = ReadOnlySqlGuard::sanitize($applied['sql'], self::MAX_ROWS);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            $probeSql = 'SELECT * FROM ('.$safe.') AS widget_probe LIMIT 1';

            $rows = ReadOnlySqlGuard::normalizeRows(
                $this->router->query($probeSql, $applied['bindings'])
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return [
            'ok' => true,

            'columns' => $rows === [] ? [] : array_keys($rows[0]),
        ];
    }
}
