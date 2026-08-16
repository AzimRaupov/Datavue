<?php

use App\Helpers\Widget\WidgetSpecValidator;
use App\Helpers\Widget\WidgetShapeMapper;

/**
 * Контракт колонок — договор между аналитиком и платформой: он же показывается
 * в редакторе подсказкой и он же проверяется при сохранении. Если эти два
 * списка разойдутся, автор будет писать запрос под одну подсказку, а получать
 * отказ по другой.
 */

it('требует ось и значение у семейств со сравнением', function () {
    foreach (['bar', 'line', 'radar', 'combo', 'heatmap'] as $family) {
        expect(WidgetSpecValidator::requiredColumns($family))
            ->toBe(['series', 'category', 'value']);
    }
});

it('требует подпись и значение у плоских семейств', function () {
    foreach (['pie', 'radial', 'funnel', 'treemap'] as $family) {
        expect(WidgetSpecValidator::requiredColumns($family))
            ->toBe(['label', 'value']);
    }
});

it('требует имя и значение у счётчиков', function () {
    expect(WidgetSpecValidator::requiredColumns('mini-counters'))->toBe(['name', 'value']);
});

it('добавляет колонку под вариант отрисовки', function () {
    // Пузырьку нужен третий размер, счётчику с прогрессом — процент.
    expect(WidgetSpecValidator::requiredColumns('scatter', 'bubble'))
        ->toBe(['series', 'x', 'y', 'z'])
        ->and(WidgetSpecValidator::requiredColumns('mini-counters', 'with-progress'))
        ->toBe(['name', 'value', 'percent']);
});

it('не требует ничего от таблицы', function () {
    // У таблицы заголовки берутся из псевдонимов запроса — фиксированного
    // набора колонок у неё нет.
    expect(WidgetSpecValidator::requiredColumns('table'))->toBe([]);
});

it('подставляет форму по семейству, а не по желанию автора', function () {
    $spec = WidgetSpecValidator::build('bar', '  SELECT 1 AS value  ');

    expect($spec['shape'])->toBe(WidgetShapeMapper::SHAPE_SERIES_MATRIX)
        ->and($spec['queries']['main'])->toBe('SELECT 1 AS value')
        // Пустое оформление в спецификацию не попадает.
        ->and($spec)->not->toHaveKey('presentation');
});

it('кладёт оформление в спецификацию отдельно от запроса', function () {
    $spec = WidgetSpecValidator::build('combo', 'SELECT 1', ['series_kinds' => ['Выручка' => 'column']]);

    expect($spec['presentation'])->toBe(['series_kinds' => ['Выручка' => 'column']]);
});

it('не знает формы для незарегистрированного семейства', function () {
    WidgetSpecValidator::requiredColumns('несуществующее');
})->throws(RuntimeException::class);

it('меняет цвета, не трогая остальное оформление', function () {
    // series_kinds выбрала модель при генерации, и человек в шторке о нём
    // не знает — правка цвета не должна его стирать.
    $spec = [
        'queries' => ['main' => 'SELECT 1'],
        'shape' => 'series_matrix',
        'presentation' => ['series_kinds' => ['Выручка' => 'column']],
    ];

    $result = WidgetSpecValidator::withColors($spec, ['#ff0000', '', '#00ff00', '', '']);

    expect($result['presentation']['series_kinds'])->toBe(['Выручка' => 'column'])
        // Пустой хвост отброшен, пустая ячейка в середине сохранена:
        // позиция цвета — это номер ряда.
        ->and($result['presentation']['colors'])->toBe(['#ff0000', '', '#00ff00'])
        ->and($result['queries'])->toBe(['main' => 'SELECT 1']);
});

it('возвращает стандартную палитру пустым списком', function () {
    $spec = [
        'queries' => ['main' => 'SELECT 1'],
        'presentation' => ['series_kinds' => ['Выручка' => 'column'], 'colors' => ['#ff0000']],
    ];

    $result = WidgetSpecValidator::withColors($spec, []);

    expect($result['presentation'])->toBe(['series_kinds' => ['Выручка' => 'column']]);
});

it('убирает оформление целиком, когда в нём ничего не осталось', function () {
    $spec = ['queries' => ['main' => 'SELECT 1'], 'presentation' => ['colors' => ['#ff0000']]];

    $result = WidgetSpecValidator::withColors($spec, ['', '']);

    expect($result)->not->toHaveKey('presentation')
        ->and($result['queries'])->toBe(['main' => 'SELECT 1']);
});

it('не трогает спецификацию, когда цвета не присылали', function () {
    $spec = ['queries' => ['main' => 'SELECT 1'], 'presentation' => ['colors' => ['#ff0000']]];

    expect(WidgetSpecValidator::withColors($spec, null))->toBe($spec);
});

it('показывает первый именной запрос спецификации', function () {
    // У счётчиков запросов бывает несколько — редактор видит только первый
    // (см. ManualWidgetAuthor::nextQuerySpec()), поэтому 'main' всегда
    // приоритетнее, а без него берётся первый по порядку.
    expect(WidgetSpecValidator::primaryQueryOf(['queries' => ['main' => 'SELECT 1', 'extra' => 'SELECT 2']]))
        ->toBe('SELECT 1')
        ->and(WidgetSpecValidator::primaryQueryOf(['queries' => ['clients' => 'SELECT 1', 'orders' => 'SELECT 2']]))
        ->toBe('SELECT 1')
        ->and(WidgetSpecValidator::primaryQueryOf(['query' => 'SELECT 3']))
        ->toBe('SELECT 3')
        ->and(WidgetSpecValidator::primaryQueryOf([]))
        ->toBeNull();
});
