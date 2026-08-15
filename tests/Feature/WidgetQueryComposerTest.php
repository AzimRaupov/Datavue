<?php

use App\Helpers\DataSource\SourceSchema;
use App\Models\Company;
use App\Helpers\Widget\WidgetQueryComposer;
use App\Models\DataSource;
use App\Models\DataSourceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * Сборщик запроса из настроек конструктора.
 *
 * Схему источника подменяем кэшем: класс читает её через SourceSchema, а тесту
 * важно поведение сборщика, а не то, какие таблицы окажутся в базе. Схема здесь
 * — это ещё и белый список: всё, чего в ней нет, до запроса не доходит.
 */

beforeEach(function () {
    DataSourceType::query()->firstOrCreate(['name' => 'mysql']);

    $company = Company::query()->create(['name' => 'Acme']);

    $this->source = DataSource::query()->create([
        'company_id' => $company->id,
        'type_id' => DataSourceType::query()->where('name', 'mysql')->value('id'),
        'connection_type' => 'remote',
        'name' => 'Продажи',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'shop',
        'username' => 'root',
        'password' => 'secret',
    ]);

    Cache::put("datasource:{$this->source->id}:schema", [
        [
            'name' => 'orders',
            'columns' => [
                ['name' => 'id', 'type' => 'int', 'kind' => 'number'],
                ['name' => 'amount', 'type' => 'decimal(10,2)', 'kind' => 'number'],
                ['name' => 'country', 'type' => 'varchar(64)', 'kind' => 'string'],
                ['name' => 'status', 'type' => 'varchar(32)', 'kind' => 'string'],
                ['name' => 'created_at', 'type' => 'datetime', 'kind' => 'date'],
            ],
        ],
    ], now()->addMinutes(5));

    $this->composer = new WidgetQueryComposer($this->source->fresh(['type']));
});

afterEach(function () {
    SourceSchema::forget($this->source->id);
});

it('собирает запрос со столбцами: ряд, ось, значение', function () {
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'sum', 'column' => 'amount', 'label' => 'Выручка']],
        'dimensions' => [['column' => 'country']],
        'limit' => 10,
    ], 'bar');

    expect($result['ok'])->toBeTrue()
        ->and($result['sql'])->toContain("'Выручка' AS `series`")
        ->and($result['sql'])->toContain('`country` AS `category`')
        ->and($result['sql'])->toContain('SUM(`amount`) AS `value`')
        ->and($result['sql'])->toContain('GROUP BY `country`')
        // Топ по значению — то, чего ждут от такого графика.
        ->and($result['sql'])->toContain('ORDER BY `value` DESC')
        ->and($result['sql'])->toContain('LIMIT 10');
});

it('округляет дату до периода и сортирует ось по возрастанию', function () {
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'count', 'label' => 'Заказов']],
        'dimensions' => [['column' => 'created_at', 'grain' => 'month']],
    ], 'line');

    expect($result['ok'])->toBeTrue()
        ->and($result['sql'])->toContain("DATE_FORMAT(`created_at`, '%Y-%m')")
        // Ось времени, идущая справа налево, не читается — по ней сортируем
        // по возрастанию, а не по величине метрики.
        ->and($result['sql'])->toContain("ORDER BY DATE_FORMAT(`created_at`, '%Y-%m') ASC");
});

it('разворачивает несколько метрик в ряды', function () {
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [
            ['agg' => 'sum', 'column' => 'amount', 'label' => 'Выручка'],
            ['agg' => 'count', 'label' => 'Заказов'],
        ],
        'dimensions' => [['column' => 'country']],
    ], 'bar');

    expect($result['ok'])->toBeTrue()
        ->and($result['sql'])->toContain('UNION ALL')
        ->and($result['sql'])->toContain("'Выручка' AS `series`")
        ->and($result['sql'])->toContain("'Заказов' AS `series`");
});

it('вторую разбивку делает рядами', function () {
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'count', 'label' => 'Заказов']],
        'dimensions' => [['column' => 'country'], ['column' => 'status']],
    ], 'bar');

    expect($result['ok'])->toBeTrue()
        ->and($result['sql'])->toContain('`status` AS `series`')
        ->and($result['sql'])->toContain('`country` AS `category`')
        ->and($result['sql'])->toContain('GROUP BY `country`, `status`')
        // Сортировка по оси: «топ по значению» перемешал бы категории
        // между рядами, и ось встала бы в случайном порядке.
        ->and($result['sql'])->toContain('ORDER BY `country` ASC');
});

it('не даёт совместить вторую разбивку с несколькими метриками', function () {
    // Иначе получились бы ряды «Выручка / Москва», «Заказы / Москва»… —
    // на графике это нечитаемо.
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'count'], ['agg' => 'sum', 'column' => 'amount']],
        'dimensions' => [['column' => 'country'], ['column' => 'status']],
    ], 'bar');

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0])->toContain('одну метрику');
});

it('каждую метрику превращает в плитку счётчика', function () {
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [
            ['agg' => 'count', 'label' => 'Заказов'],
            ['agg' => 'count_distinct', 'column' => 'country', 'label' => 'Стран'],
        ],
    ], 'mini-counters');

    expect($result['ok'])->toBeTrue()
        ->and($result['sql'])->toContain("'Заказов' AS `name`")
        ->and($result['sql'])->toContain('COUNT(DISTINCT `country`) AS `value`')
        ->and($result['sql'])->toContain('UNION ALL');
});

it('требует ось у графиков сравнения', function () {
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'count']],
    ], 'bar');

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0])->toContain('разбивку');
});

it('строит условия отбора', function () {
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'count']],
        'dimensions' => [['column' => 'country']],
        'filters' => [
            ['column' => 'status', 'op' => '=', 'value' => 'shipped'],
            ['column' => 'amount', 'op' => '>', 'value' => 1000],
            ['column' => 'country', 'op' => 'in', 'value' => 'USA, Spain'],
            ['column' => 'created_at', 'op' => 'between', 'value' => ['2026-01-01', '2026-06-30']],
            ['column' => 'status', 'op' => 'not_null'],
        ],
    ], 'bar');

    expect($result['ok'])->toBeTrue()
        ->and($result['sql'])->toContain("`status` = 'shipped'")
        ->and($result['sql'])->toContain('`amount` > 1000')
        ->and($result['sql'])->toContain("`country` IN ('USA', 'Spain')")
        ->and($result['sql'])->toContain("`created_at` BETWEEN '2026-01-01' AND '2026-06-30'")
        ->and($result['sql'])->toContain('`status` IS NOT NULL');
});

// ---------------------------------------------------------------------------
// Что в запрос не попадает
// ---------------------------------------------------------------------------

it('не пускает в запрос колонку, которой нет в таблице', function () {
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'sum', 'column' => 'секрет']],
        'dimensions' => [['column' => 'country']],
    ], 'bar');

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0])->toContain('нет колонки');
});

it('не пускает в запрос чужую таблицу', function () {
    $result = $this->composer->compose([
        'table' => 'users',
        'metrics' => [['agg' => 'count']],
        'dimensions' => [['column' => 'country']],
    ], 'bar');

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0])->toContain('нет таблицы');
});

it('отклоняет SQL, спрятанный в имени колонки', function () {
    // Имя колонки не экранируется «на всякий случай», а проверяется по схеме:
    // выражения, которого нет в таблице, не существует.
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'count']],
        'dimensions' => [['column' => 'country`, (SELECT password FROM users) AS `x']],
    ], 'bar');

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0])->toContain('нет колонки');
});

it('экранирует кавычки в значении условия', function () {
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'count']],
        'dimensions' => [['column' => 'country']],
        'filters' => [['column' => 'status', 'op' => '=', 'value' => "x' OR 1=1 --"]],
    ], 'bar');

    expect($result['ok'])->toBeTrue()
        // Кавычка удвоена: значение осталось значением и условием не стало.
        ->and($result['sql'])->toContain("`status` = 'x'' OR 1=1 --'")
        ->and($result['sql'])->not->toContain("= 'x' OR 1=1");
});

it('не пускает текст туда, где ждали число', function () {
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'count']],
        'dimensions' => [['column' => 'country']],
        'filters' => [['column' => 'amount', 'op' => '>', 'value' => '1 OR 1=1']],
    ], 'bar');

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0])->toContain('не число');
});

it('не принимает дату, которая не дата', function () {
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'count']],
        'dimensions' => [['column' => 'country']],
        'filters' => [['column' => 'created_at', 'op' => '>', 'value' => "2026-01-01' OR '1'='1"]],
    ], 'bar');

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0])->toContain('Некорректная дата');
});

it('не считает сумму по строковой колонке', function () {
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'sum', 'column' => 'country']],
        'dimensions' => [['column' => 'status']],
    ], 'bar');

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0])->toContain('не числовая');
});

it('не округляет по периодам недату', function () {
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'count']],
        'dimensions' => [['column' => 'country', 'grain' => 'month']],
    ], 'bar');

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0])->toContain('не дата');
});

it('отклоняет неизвестную функцию и неизвестное условие', function () {
    $bad = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'exec', 'column' => 'amount']],
        'dimensions' => [['column' => 'country']],
    ], 'bar');

    expect($bad['ok'])->toBeFalse()
        ->and($bad['errors'][0])->toContain('функция');

    $badOp = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'count']],
        'dimensions' => [['column' => 'country']],
        'filters' => [['column' => 'status', 'op' => 'DROP', 'value' => 'x']],
    ], 'bar');

    expect($badOp['ok'])->toBeFalse()
        ->and($badOp['errors'][0])->toContain('условие');
});

it('не собирает запрос без единой метрики', function () {
    // Раньше пустой список молча доходил до сборки, и получался обрубок
    // «SELECT country AS label, AS value» — база отвечала невнятной
    // ошибкой синтаксиса вместо понятной подсказки.
    foreach (['bar', 'pie', 'mini-counters'] as $family) {
        $result = $this->composer->compose([
            'table' => 'orders',
            'metrics' => [],
            'dimensions' => [['column' => 'country']],
        ], $family);

        expect($result['ok'])->toBeFalse()
            ->and($result['errors'][0])->toContain('метрику')
            ->and($result['sql'])->toBeNull();
    }
});

it('не режет плитки счётчика лимитом строк', function () {
    // Модель охотно ставит limit по числу метрик, а иногда и меньше.
    // Здесь строка результата — это метрика, поэтому лимит не должен
    // молча убирать показатели, которых просили.
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [
            ['agg' => 'count', 'label' => 'Заказов'],
            ['agg' => 'sum', 'column' => 'amount', 'label' => 'Выручка'],
            ['agg' => 'avg', 'column' => 'amount', 'label' => 'Средний чек'],
        ],
        'limit' => 1,
    ], 'mini-counters');

    expect($result['ok'])->toBeTrue()
        ->and($result['sql'])->toContain('LIMIT 3')
        ->and(substr_count($result['sql'], 'UNION ALL'))->toBe(2);
});

it('требует разбивку у точечной диаграммы', function () {
    // Без разбивки агрегаты считаются по всей таблице, и на графике
    // оказывается ровно одна точка — виджет из этого бессмысленный.
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [
            ['agg' => 'avg', 'column' => 'amount'],
            ['agg' => 'count'],
        ],
    ], 'scatter');

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0])->toContain('одну точку');
});

it('держит число строк в разумных пределах', function () {
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'count']],
        'dimensions' => [['column' => 'country']],
        'limit' => 999999,
    ], 'bar');

    expect($result['sql'])->toContain('LIMIT '.WidgetQueryComposer::MAX_LIMIT);
});

it('собирает запрос для каждого семейства каталога', function () {
    // Проверка «конструктор умеет собрать любой виджет, который можно
    // выбрать в палитре». Раньше точечная и комбо-диаграмма молча
    // не собирались: слоты просили не то число метрик.
    Cache::put("datasource:{$this->source->id}:schema", [
        [
            'name' => 'orders',
            'columns' => [
                ['name' => 'amount', 'type' => 'decimal(10,2)', 'kind' => 'number'],
                ['name' => 'country', 'type' => 'varchar(64)', 'kind' => 'string'],
                ['name' => 'status', 'type' => 'varchar(32)', 'kind' => 'string'],
            ],
        ],
    ], now()->addMinutes(5));

    $composer = new WidgetQueryComposer($this->source->fresh(['type']));

    $families = [
        'mini-counters', 'bar', 'line', 'pie', 'radial', 'funnel',
        'treemap', 'combo', 'radar', 'heatmap', 'scatter', 'table', 'map',
    ];

    foreach ($families as $family) {
        $slots = WidgetQueryComposer::slotsFor($family);

        // Набираем ровно столько слотов, сколько семейство требует, —
        // именно это делает конструктор при выборе таблицы.
        $metrics = [];
        for ($i = 0; $i < max(1, $slots['metrics']['min']); $i++) {
            $metrics[] = $i === 0
                ? ['agg' => 'count', 'label' => 'Заказов']
                : ['agg' => 'sum', 'column' => 'amount', 'label' => 'Сумма '.$i];
        }

        $dimensions = [];
        for ($i = 0; $i < $slots['dimensions']['min']; $i++) {
            $dimensions[] = ['column' => $i === 0 ? 'country' : 'status'];
        }

        $result = $composer->compose([
            'table' => 'orders',
            'metrics' => $metrics,
            'dimensions' => $dimensions,
            'limit' => 10,
        ], $family);

        expect($result['ok'])
            ->toBeTrue("семейство «{$family}» не собралось: ".implode('; ', $result['errors']));
    }
});

it('сам расставляет тип рядов комбо-графику', function () {
    Cache::put("datasource:{$this->source->id}:schema", [
        [
            'name' => 'orders',
            'columns' => [
                ['name' => 'amount', 'type' => 'decimal(10,2)', 'kind' => 'number'],
                ['name' => 'country', 'type' => 'varchar(64)', 'kind' => 'string'],
            ],
        ],
    ], now()->addMinutes(5));

    $composer = new WidgetQueryComposer($this->source->fresh(['type']));

    $result = $composer->compose([
        'table' => 'orders',
        'metrics' => [
            ['agg' => 'sum', 'column' => 'amount', 'label' => 'Выручка'],
            ['agg' => 'count', 'label' => 'Заказов'],
        ],
        'dimensions' => [['column' => 'country']],
    ], 'combo');

    // Без этого комбо неотличим от гистограммы, и проверка формы его
    // отклоняет — а просить такое у автора незачем: порядок метрик всё сказал.
    expect($result['ok'])->toBeTrue()
        ->and($result['presentation']['series_kinds'])->toBe([
            'Выручка' => 'column',
            'Заказов' => 'line',
        ]);
});

it('требует у комбо две метрики, а у точечной — две и разбивку', function () {
    expect(WidgetQueryComposer::slotsFor('combo')['metrics']['min'])->toBe(2)
        ->and(WidgetQueryComposer::slotsFor('combo')['dimensions']['min'])->toBe(1)
        ->and(WidgetQueryComposer::slotsFor('scatter')['metrics']['min'])->toBe(2)
        ->and(WidgetQueryComposer::slotsFor('scatter')['dimensions']['min'])->toBe(1)
        ->and(WidgetQueryComposer::slotsFor('scatter', 'bubble')['metrics']['min'])->toBe(3)
        // Карте нужен код страны, а не любая подпись — об этом сказано сразу.
        ->and(WidgetQueryComposer::slotsFor('map')['hint'])->toContain('код страны');
});

it('считает процент выполнения у счётчика с полосой', function () {
    // Виду with-progress нужна третья колонка. Пока конструктор её не
    // добавлял, такой счётчик собрать было нельзя вовсе: запрос отдавал
    // name и value, а проверка формы требовала percent.
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'count', 'label' => 'Заказов', 'target' => 200]],
    ], 'mini-counters', 'with-progress');

    expect($result['ok'])->toBeTrue()
        ->and($result['sql'])->toContain('AS `percent`')
        ->and($result['sql'])->toContain('ROUND(100 * COUNT(*) / 200, 1)')
        // Полоса, залитая на 300%, не читается — процент держим в шкале.
        ->and($result['sql'])->toContain('LEAST(100, GREATEST(0,');
});

it('без цели считает процентом саму метрику', function () {
    // Так задают показатель, который уже посчитан в процентах:
    // средняя загрузка, доля выполненных.
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'avg', 'column' => 'amount', 'label' => 'Загрузка']],
    ], 'mini-counters', 'with-progress');

    expect($result['ok'])->toBeTrue()
        ->and($result['sql'])->toContain('LEAST(100, GREATEST(0, AVG(`amount`))) AS `percent`')
        ->and($result['sql'])->not->toContain('ROUND(100 *');
});

it('не добавляет процент обычному счётчику', function () {
    $result = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'count', 'label' => 'Заказов', 'target' => 200]],
    ], 'mini-counters', 'cards');

    expect($result['sql'])->not->toContain('percent');
});

it('добавляет процент каждой плитке и разбивке', function () {
    $many = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [
            ['agg' => 'count', 'label' => 'Заказов', 'target' => 100],
            ['agg' => 'sum', 'column' => 'amount', 'label' => 'Выручка', 'target' => 5000],
        ],
    ], 'mini-counters', 'with-progress');

    // По плитке на метрику — процент нужен в каждой части объединения.
    expect(substr_count($many['sql'], 'AS `percent`'))->toBe(2);

    $byDimension = $this->composer->compose([
        'table' => 'orders',
        'metrics' => [['agg' => 'count', 'label' => 'Заказов', 'target' => 50]],
        'dimensions' => [['column' => 'country']],
    ], 'mini-counters', 'with-progress');

    expect($byDimension['ok'])->toBeTrue()
        ->and($byDimension['sql'])->toContain('AS `percent`')
        ->and($byDimension['sql'])->toContain('GROUP BY `country`');
});

it('игнорирует цель, которой нельзя пользоваться', function () {
    // Ноль и текст в цели: делить на них нечем, поэтому метрика считается
    // процентом сама — виджет остаётся рабочим.
    foreach ([0, -5, 'много', ''] as $target) {
        $result = $this->composer->compose([
            'table' => 'orders',
            'metrics' => [['agg' => 'count', 'label' => 'Заказов', 'target' => $target]],
        ], 'mini-counters', 'with-progress');

        expect($result['ok'])->toBeTrue()
            ->and($result['sql'])->not->toContain('ROUND(100 *');
    }
});

it('говорит конструктору, что виду нужна цель', function () {
    expect(WidgetQueryComposer::slotsFor('mini-counters', 'with-progress')['needs_target'])->toBeTrue()
        ->and(WidgetQueryComposer::slotsFor('mini-counters', 'cards')['needs_target'])->toBeFalse();
});
