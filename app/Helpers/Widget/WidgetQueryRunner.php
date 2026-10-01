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

    public const MAX_ROWS = 50;

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

        try {

            $multi = count($queries) > 1;

            if ($multi) {
                $rows = [];

                foreach ($queries as $name => $sql) {
                    $prepared = $this->prepare($sql, $filters, $input, stripLimit: false);

                    foreach ($this->fetch($prepared, $this->sampleMeta(self::MAX_ROWS))['rows'] as $row) {
                        $rows[] = $row;
                    }
                }

                $meta = $this->sampleMeta(self::MAX_ROWS);
            } else {
                $prepared = $this->prepare(reset($queries), $filters, $input);

                $meta = $sample
                    ? $this->sampleMeta()
                    : $this->buildMeta($prepared, $filters, $input);

                $fetched = $this->fetch($prepared, $meta);
                $rows = $fetched['rows'];

                if ($fetched['truncated']) {
                    $meta['truncated'] = true;
                }
            }
        } catch (Throwable $e) {

            return ['ok' => false, 'error' => $e->getMessage()];
        }

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

        $searchColumns = $search !== '' ? $this->searchColumns($prepared) : [];

        $total = $paginated
            ? $this->countRows($prepared, $search, $searchColumns)
            : null;

        return [
            'paginated' => $paginated,
            'limit' => $limit,
            'search_columns' => $searchColumns,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $paginated && $perPage > 0 ? (int) ceil($total / $perPage) : null,
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
            'total' => null,
            'page' => 1,
            'per_page' => self::SAMPLE_ROWS,
            'pages' => null,
            'search' => null,
            'truncated' => false,
        ];
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

            $limit = $meta['limit'] ?? (self::MAX_ROWS + 1);
        }

        $wrapped = $this->wrap(
            $prepared,
            $meta['search'] ?? '',
            $meta['search_columns'] ?? [],
            $limit,
            $offset
        );

        $rows = ReadOnlySqlGuard::normalizeRows(
            $this->router->query($wrapped['sql'], $wrapped['bindings'])
        );

        $truncated = false;

        if (!$meta['paginated'] && $meta['limit'] === null && count($rows) > self::MAX_ROWS) {
            $truncated = true;
            $rows = array_slice($rows, 0, self::MAX_ROWS);
        }

        return ['rows' => $rows, 'truncated' => $truncated];
    }

    private function wrap(
        array $prepared,
        string $search,
        array $searchColumns,
        ?int $limit,
        ?int $offset
    ): array {
        $bindings = $prepared['bindings'];

        $sql = 'SELECT * FROM ('.$prepared['sql'].') AS widget_base';

        if ($search !== '' && $searchColumns !== []) {
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
