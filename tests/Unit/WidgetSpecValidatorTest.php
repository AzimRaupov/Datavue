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
