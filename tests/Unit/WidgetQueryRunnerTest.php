<?php

use App\Helpers\DataSource\ConnectionProviderRouter;
use App\Helpers\Widget\WidgetQueryRunner;
use App\Models\DataSource;
use App\Models\DataSourceType;

/**
 * Роутер без базы: запоминает SQL и отдаёт строки, которые вернёт обработчик.
 */
class RunnerFakeRouter extends ConnectionProviderRouter
{
    public array $log = [];

    public function __construct(private Closure $handler)
    {
    }

    public function query($query, $bindings = [])
    {
        $this->log[] = $query;

        return ($this->handler)($query);
    }
}

function makeRunner(Closure $handler): array
{
    $source = new DataSource(['name' => 'fake']);
    $source->setRelation('type', new DataSourceType(['name' => 'mysql']));

    $router = new RunnerFakeRouter($handler);

    return [new WidgetQueryRunner($source, $router), $router];
}

function matrixSpec(): array
{
    return ['shape' => 'series_matrix', 'queries' => ['main' => 'SELECT 1 AS series, 1 AS category, 1 AS value']];
}

it('отдаёт графику больше пятидесяти строк целиком', function () {
    [$runner, $router] = makeRunner(function () {
        $rows = [];

        for ($i = 0; $i < 120; $i++) {
            $rows[] = ['series' => 'Заказов', 'category' => sprintf('2024-%03d', $i), 'value' => $i];
        }

        return $rows;
    });

    $result = $runner->run(matrixSpec(), 'line');

    expect($result['ok'])->toBeTrue()
        ->and($result['data']['labels'])->toHaveCount(120)
        ->and($result['data']['series'][0]['data'])->toHaveCount(120)
        ->and($result['meta']['truncated'])->toBeFalse()
        ->and($router->log[0])->toContain('LIMIT '.(WidgetQueryRunner::MAX_FETCH_ROWS + 1));
});

it('при обрезке по потолку отбрасывает неполную последнюю категорию', function () {
    // Три ряда на категорию: 5001 строка заканчивается на второй ячейке категории.
    [$runner] = makeRunner(function () {
        $rows = [];

        for ($i = 0; $i <= WidgetQueryRunner::MAX_FETCH_ROWS; $i++) {
            $rows[] = [
                'series' => 's'.($i % 3),
                'category' => sprintf('c%05d', intdiv($i, 3)),
                'value' => 5,
            ];
        }

        return $rows;
    });

    $result = $runner->run(matrixSpec(), 'bar');

    $complete = intdiv(WidgetQueryRunner::MAX_FETCH_ROWS, 3); // 1666 целых категорий

    expect($result['meta']['truncated'])->toBeTrue()
        ->and($result['data']['categories'])->toHaveCount($complete)
        ->and($result['data']['series'])->toHaveCount(3);

    foreach ($result['data']['series'] as $series) {
        // Ни одного значения, дорисованного нулём.
        expect($series['data'])->each->toBe(5);
    }
});

it('листает таблицу на сервере и держит порядок исходного запроса', function () {
    [$runner, $router] = makeRunner(function (string $sql) {
        if (str_contains($sql, 'COUNT(*)')) {
            return [['total' => 57]];
        }

        return [['name' => 'Анна', 'sum' => 10]];
    });

    $spec = ['shape' => 'rows', 'queries' => ['main' => 'SELECT name, sum FROM t ORDER BY sum DESC LIMIT 100']];

    $result = $runner->run($spec, 'table', null, ['paginate' => ['per_page' => 25], 'search' => []], ['page' => 3]);

    expect($result['meta'])->toMatchArray([
        'paginated' => true,
        'total' => 57,
        'page' => 3,
        'per_page' => 25,
        'pages' => 3,
    ]);

    $page = end($router->log);

    // Лимит исходного запроса снят, страница вырезана в нём самом — без
    // подзапроса, у которого MySQL вправе выбросить ORDER BY.
    expect($page)->toBe('SELECT name, sum FROM t ORDER BY sum DESC LIMIT 25 OFFSET 50');
});

it('возвращает последнюю страницу, если запрошена несуществующая', function () {
    [$runner, $router] = makeRunner(fn (string $sql) => str_contains($sql, 'COUNT(*)') ? [['total' => 30]] : [['a' => 1]]);

    $spec = ['shape' => 'rows', 'queries' => ['main' => 'SELECT a FROM t']];

    $result = $runner->run($spec, 'table', null, ['paginate' => []], ['page' => 99, 'per_page' => 10]);

    expect($result['meta']['page'])->toBe(3)
        ->and(end($router->log))->toEndWith('LIMIT 10 OFFSET 20');
});

it('сортирует таблицу по колонке из результата и не пускает чужие имена', function () {
    [$runner, $router] = makeRunner(fn (string $sql) => str_contains($sql, 'COUNT(*)') ? [['total' => 5]] : [['name' => 'Анна', 'sum' => 10]]);

    $spec = ['shape' => 'rows', 'queries' => ['main' => 'SELECT name, sum FROM t']];
    $filters = ['paginate' => []];

    $runner->run($spec, 'table', null, $filters, ['sort_by' => 'sum', 'sort_dir' => 'desc']);

    expect(end($router->log))->toContain('ORDER BY `sum` DESC');

    $result = $runner->run($spec, 'table', null, $filters, ['sort_by' => '1; DROP TABLE t']);

    expect($result['meta']['sort'])->toBeNull()
        ->and(end($router->log))->not->toContain('ORDER BY');
});

it('ищет по всей таблице, а не по странице', function () {
    [$runner, $router] = makeRunner(fn (string $sql) => str_contains($sql, 'COUNT(*)') ? [['total' => 2]] : [['name' => 'Анна']]);

    $spec = ['shape' => 'rows', 'queries' => ['main' => 'SELECT name FROM t']];

    $result = $runner->run($spec, 'table', null, ['paginate' => [], 'search' => []], ['search' => 'Ан']);

    expect($result['meta']['search'])->toBe('Ан')
        ->and($result['meta']['total'])->toBe(2)
        ->and(end($router->log))->toContain('LIKE');
});
