<?php

use App\Helpers\Widget\WidgetShapeMapper;

/**
 * Маппер — ядро перехода с Python на SQL: именно он собирает вложенную схему
 * виджета, которую раньше модель писала руками на каждом виджете.
 *
 * Тесты не требуют ни базы, ни обращений к модели: на вход идут плоские
 * строки, на выходе сверяется структура.
 */

/** Строки приходят из драйвера объектами — так и подаём. */
function rows(array $items): array
{
    return array_map(fn ($item) => (object) $item, $items);
}

it('раскладывает label/value в круговой виджет', function () {
    $result = (new WidgetShapeMapper())->map('pie', 'donut', 'series_values', rows([
        ['label' => 'Россия', 'value' => 120],
        ['label' => 'Германия', 'value' => 80],
    ]));

    expect($result)->toBe([
        'series' => [120, 80],
        'labels' => ['Россия', 'Германия'],
    ]);
});

it('упаковывает те же данные по-разному для treemap и map', function () {
    $mapper = new WidgetShapeMapper();

    $source = rows([
        ['label' => 'RU', 'value' => 10],
        ['label' => 'DE', 'value' => 5],
    ]);

    expect($mapper->map('treemap', null, 'series_values', $source))
        ->toBe(['series' => [['data' => [
            ['x' => 'RU', 'y' => 10],
            ['x' => 'DE', 'y' => 5],
        ]]]]);

    expect($mapper->map('map', null, 'series_values', $source))
        ->toBe(['series' => [
            ['code' => 'RU', 'value' => 10],
            ['code' => 'DE', 'value' => 5],
        ]]);
});

it('сводит строки в матрицу и заполняет пропуски нулями', function () {
    // Пары (Мужчины, 2 курс) в данных нет — на её месте обязан оказаться 0,
    // иначе длина data разойдётся с осью и график съедет.
    $result = (new WidgetShapeMapper())->map('bar', 'column', 'series_matrix', rows([
        ['series' => 'Женщины', 'category' => '1 курс', 'value' => 12],
        ['series' => 'Женщины', 'category' => '2 курс', 'value' => 34],
        ['series' => 'Мужчины', 'category' => '1 курс', 'value' => 9],
    ]));

    expect($result)->toBe([
        'series' => [
            ['name' => 'Женщины', 'data' => [12, 34]],
            ['name' => 'Мужчины', 'data' => [9, 0]],
        ],
        'categories' => ['1 курс', '2 курс'],
    ]);
});

it('оставляет числовые подписи оси строками', function () {
    // Уникальность категорий держится на ключах массива, а PHP приводит
    // числовой ключ «2024» к int — без strval подписи уехали бы числами.
    $result = (new WidgetShapeMapper())->map('bar', null, 'series_matrix', rows([
        ['series' => 'Выручка', 'category' => '2023', 'value' => 10],
        ['series' => 'Выручка', 'category' => '2024', 'value' => 20],
    ]));

    expect($result['categories'])->toBe(['2023', '2024'])
        ->and($result['categories'][0])->toBeString();
});

it('называет ось labels для линейного графика и categories для остальных', function () {
    $source = rows([
        ['series' => 'Выручка', 'category' => 'Январь', 'value' => 100],
    ]);

    $mapper = new WidgetShapeMapper();

    expect($mapper->map('line', null, 'series_matrix', $source))->toHaveKey('labels');
    expect($mapper->map('bar', null, 'series_matrix', $source))->toHaveKey('categories');
});

it('проставляет kind рядам комбинированного графика', function () {
    $result = (new WidgetShapeMapper())->map('combo', null, 'series_matrix', rows([
        ['series' => 'Выручка', 'category' => 'Янв', 'value' => 100],
        ['series' => 'Средний чек', 'category' => 'Янв', 'value' => 7],
    ]), [
        'series_kinds' => ['Выручка' => 'column', 'Средний чек' => 'line'],
    ]);

    expect($result['series'][0]['kind'])->toBe('column')
        ->and($result['series'][1]['kind'])->toBe('line');
});

it('кладёт подпись оси внутрь точки для тепловой карты', function () {
    $result = (new WidgetShapeMapper())->map('heatmap', null, 'series_matrix', rows([
        ['series' => 'Пн', 'category' => '10:00', 'value' => 3],
    ]));

    expect($result)->toBe(['series' => [
        ['name' => 'Пн', 'data' => [['x' => '10:00', 'y' => 3]]],
    ]]);
});

it('разворачивает радар в плоский список для polar-area', function () {
    $result = (new WidgetShapeMapper())->map('radar', 'polar-area', 'series_matrix', rows([
        ['series' => 'Навыки', 'category' => 'SQL', 'value' => 80],
        ['series' => 'Навыки', 'category' => 'Python', 'value' => 50],
    ]));

    expect($result)->toBe(['series' => [80, 50], 'labels' => ['SQL', 'Python']]);
});

it('группирует точки по рядам и добавляет размер для bubble', function () {
    $mapper = new WidgetShapeMapper();

    $plain = $mapper->map('scatter', 'scatter', 'points', rows([
        ['series' => 'A', 'x' => 1, 'y' => 2],
        ['series' => 'A', 'x' => 3, 'y' => 4],
        ['series' => 'B', 'x' => 5, 'y' => 6],
    ]));

    expect($plain['series'])->toBe([
        ['name' => 'A', 'data' => [[1, 2], [3, 4]]],
        ['name' => 'B', 'data' => [[5, 6]]],
    ]);

    $bubble = $mapper->map('scatter', 'bubble', 'points', rows([
        ['series' => 'A', 'x' => 1, 'y' => 2, 'z' => 9],
    ]));

    expect($bubble['series'][0]['data'][0])->toBe([1, 2, 9]);
});

it('собирает счётчики и добавляет percent для варианта с прогрессом', function () {
    $mapper = new WidgetShapeMapper();

    $plain = $mapper->map('mini-counters', 'cards', 'counters', rows([
        ['name' => 'Клиентов', 'value' => 122],
        ['name' => 'Выручка', 'value' => 1500, 'suffix' => ' ₽'],
    ]));

    expect($plain['counters'][0])->toBe([
        'name' => 'Клиентов', 'value' => 122, 'prefix' => '', 'suffix' => '',
    ]);
    expect($plain['counters'][1]['suffix'])->toBe(' ₽');

    $progress = $mapper->map('mini-counters', 'with-progress', 'counters', rows([
        ['name' => 'План', 'value' => 80, 'percent' => 80],
    ]));

    expect($progress['counters'][0]['percent'])->toBe(80);
});

it('берёт заголовки таблицы из имён колонок запроса', function () {
    $result = (new WidgetShapeMapper())->map('table', 'plain', 'rows', rows([
        ['Клиент' => 'ООО Ромашка', 'Выручка' => 1500],
        ['Клиент' => 'ИП Иванов', 'Выручка' => 900],
    ]));

    expect($result['headers'])->toBe(['Клиент', 'Выручка'])
        ->and($result['rows'])->toBe([
            ['ООО Ромашка', 1500],
            ['ИП Иванов', 900],
        ]);
});

it('приводит числа-строки от драйверов к числам', function () {
    // MySQL отдаёт SUM() строкой, decimal приходит как "1234.50".
    // Без приведения фронт получил бы строку и график не построился.
    $result = (new WidgetShapeMapper())->map('pie', null, 'series_values', rows([
        ['label' => 'A', 'value' => '1234.50'],
        ['label' => 'B', 'value' => '42'],
    ]));

    expect($result['series'][0])->toBe(1234.5)
        ->and($result['series'][1])->toBe(42);
});

it('заменяет NULL в подписи на прочерк', function () {
    // Клиент без страны — обычное дело в группировке.
    $result = (new WidgetShapeMapper())->map('pie', null, 'series_values', rows([
        ['label' => null, 'value' => 7],
    ]));

    expect($result['labels'][0])->toBe('—');
});

it('знает форму для каждого семейства виджетов', function () {
    $families = [
        'mini-counters', 'bar', 'line', 'pie', 'radial', 'combo',
        'table', 'scatter', 'radar', 'heatmap', 'treemap', 'funnel', 'map',
    ];

    foreach ($families as $family) {
        expect(WidgetShapeMapper::shapeFor($family))
            ->toBeIn(WidgetShapeMapper::SHAPES);
    }
});
