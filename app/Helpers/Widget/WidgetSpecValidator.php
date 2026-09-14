<?php

namespace App\Helpers\Widget;

use App\Models\DataSource;
use Throwable;

class WidgetSpecValidator
{

    public static function requiredColumns(string $family, ?string $type = null): array
    {
        $shape = WidgetShapeMapper::shapeFor($family);

        $columns = WidgetShapeMapper::SHAPE_COLUMNS[$shape] ?? [];

        if ($shape === WidgetShapeMapper::SHAPE_POINTS && $type === 'bubble') {
            $columns[] = 'z';
        }

        if ($shape === WidgetShapeMapper::SHAPE_COUNTERS && $type === 'with-progress') {
            $columns[] = 'percent';
        }

        return $columns;
    }

    public static function build(string $family, string $sql, array $presentation = []): array
    {
        $spec = [
            'queries' => ['main' => trim($sql)],
            'shape' => WidgetShapeMapper::shapeFor($family),
        ];

        if ($presentation !== []) {
            $spec['presentation'] = $presentation;
        }

        return $spec;
    }

    public static function primaryQueryOf(array $querySpec): ?string
    {
        $queries = $querySpec['queries'] ?? $querySpec['query'] ?? null;

        if (is_string($queries)) {
            return $queries;
        }

        if (is_array($queries)) {
            $first = $queries['main'] ?? reset($queries);

            return is_string($first) ? $first : null;
        }

        return null;
    }

    public static function withColors(array $spec, ?array $colors): array
    {
        if ($colors === null) {
            return $spec;
        }

        $clean = array_map(
            fn ($color) => is_string($color) ? trim($color) : '',
            array_values($colors)
        );

        while ($clean !== [] && end($clean) === '') {
            array_pop($clean);
        }

        $presentation = $spec['presentation'] ?? [];

        if ($clean === []) {
            unset($presentation['colors']);
        } else {
            $presentation['colors'] = $clean;
        }

        if ($presentation === []) {
            unset($spec['presentation']);
        } else {
            $spec['presentation'] = $presentation;
        }

        return $spec;
    }

    public function __construct(private DataSource $dataSource)
    {
    }

    public function validate(array $spec, string $family, ?string $type = null): array
    {
        $queries = $this->queriesOf($spec);

        if ($queries === []) {
            return $this->fail('В спецификации нет ни одного запроса.');
        }

        $runner = new WidgetQueryRunner($this->dataSource);

        $columns = [];

        foreach ($queries as $name => $sql) {
            try {
                $probe = $runner->probe($sql);
            } catch (Throwable $e) {
                return $this->fail($e->getMessage());
            }

            if (!($probe['ok'] ?? false)) {
                $error = self::cleanDatabaseError((string) $probe['error']);

                return $this->fail(
                    count($queries) > 1
                        ? "Запрос «{$name}»: ".$error
                        : $error
                );
            }

            if ($columns === [] && ($probe['columns'] ?? []) !== []) {
                $columns = array_map('strval', $probe['columns']);
            }
        }

        if ($columns === []) {
            return ['ok' => true, 'errors' => [], 'columns' => []];
        }

        $required = self::requiredColumns($family, $type);
        $missing = array_diff($required, $columns);

        if ($missing !== []) {
            return $this->fail(sprintf(
                'Запрос вернул колонки [%s], а виджету «%s» нужны [%s]. Не хватает: %s. '
                . 'Задайте имена через AS — например SELECT country AS %s.',
                implode(', ', $columns),
                $family,
                implode(', ', $required),
                implode(', ', $missing),
                reset($missing)
            ), $columns);
        }

        return ['ok' => true, 'errors' => [], 'columns' => $columns];
    }

    public static function cleanDatabaseError(string $error): string
    {
        $clean = preg_replace('/\s*\(Connection:.*$/s', '', $error) ?? $error;

        return trim($clean) !== '' ? trim($clean) : $error;
    }

    private function queriesOf(array $spec): array
    {
        $queries = $spec['queries'] ?? $spec['query'] ?? null;

        if (is_string($queries)) {
            return trim($queries) === '' ? [] : ['main' => $queries];
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

        return $result;
    }

    private function fail(string $error, array $columns = []): array
    {
        return ['ok' => false, 'errors' => [$error], 'columns' => $columns];
    }
}
