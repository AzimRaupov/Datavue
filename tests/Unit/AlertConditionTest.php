<?php

use App\Helpers\Alert\AlertCondition;

/**
 * Решение «сработало или нет» по результату SQL-условия алерта.
 *
 * Отдельного внимания заслуживает пустой результат: он не угадывается,
 * а явно задан автором через on_empty — это то место, где раньше был бы
 * слепой пятно молчаливого поведения по умолчанию.
 */

it('сравнивает число строк с порогом всеми операторами', function (string $op, int $rows, float $threshold, bool $expected) {
    $result = AlertCondition::evaluate(
        ['kind' => 'rows', 'op' => $op, 'threshold' => $threshold],
        $rows,
        null
    );

    expect($result['triggered'])->toBe($expected)
        ->and($result['value'])->toBe($rows);
})->with([
    ['>', 5, 0, true],
    ['>', 0, 0, false],
    ['>=', 5, 5, true],
    ['<', 3, 5, true],
    ['<=', 5, 5, true],
    ['=', 5, 5, true],
    ['!=', 5, 4, true],
    ['!=', 5, 5, false],
]);

it('сравнивает значение колонки первой строки с порогом', function () {
    $result = AlertCondition::evaluate(
        ['kind' => 'value', 'op' => '<', 'threshold' => 100, 'column' => 'balance'],
        1,
        ['balance' => 42]
    );

    expect($result['triggered'])->toBeTrue()
        ->and($result['value'])->toBe(42);
});

it('падает понятной ошибкой, если колонки для value нет в результате', function () {
    AlertCondition::evaluate(
        ['kind' => 'value', 'op' => '<', 'threshold' => 100, 'column' => 'missing'],
        1,
        ['balance' => 42]
    );
})->throws(RuntimeException::class, 'нет колонки');

it('падает понятной ошибкой, если значение колонки не число', function () {
    AlertCondition::evaluate(
        ['kind' => 'value', 'op' => '<', 'threshold' => 100, 'column' => 'status'],
        1,
        ['status' => 'shipped']
    );
})->throws(RuntimeException::class);

it('пустой результат с on_empty=ok не срабатывает', function () {
    $result = AlertCondition::evaluate(
        ['kind' => 'rows', 'op' => '>', 'threshold' => 0, 'on_empty' => 'ok'],
        0,
        null
    );

    expect($result['triggered'])->toBeFalse();
});

it('пустой результат с on_empty=triggered срабатывает', function () {
    $result = AlertCondition::evaluate(
        ['kind' => 'rows', 'op' => '>', 'threshold' => 0, 'on_empty' => 'triggered'],
        0,
        null
    );

    expect($result['triggered'])->toBeTrue();
});

it('пустой результат с on_empty=error бросает исключение', function () {
    AlertCondition::evaluate(
        ['kind' => 'rows', 'op' => '>', 'threshold' => 0, 'on_empty' => 'error'],
        0,
        null
    );
})->throws(RuntimeException::class);

it('пустой результат по умолчанию (без on_empty) не срабатывает', function () {
    $result = AlertCondition::evaluate(['kind' => 'rows', 'op' => '>', 'threshold' => 0], 0, null);

    expect($result['triggered'])->toBeFalse();
});

it('отклоняет неизвестный оператор', function () {
    AlertCondition::evaluate(['kind' => 'rows', 'op' => 'wat', 'threshold' => 0], 5, null);
})->throws(RuntimeException::class, 'Неизвестный оператор');

it('отклоняет неизвестный вид условия', function () {
    AlertCondition::evaluate(['kind' => 'unknown'], 5, null);
})->throws(RuntimeException::class, 'Неизвестный вид условия');
