<?php

use App\Helpers\Alert\AlertOutputValidator;

/**
 * Контракт вывода Python-условия алерта.
 *
 * Ключевое правило: отсутствие "triggered" или мусор в stdout — это ОШИБКА
 * проверки, а не «условие не выполнено». Смешать эти два случая значит
 * получить алерт, который выглядит здоровым, пока молча ничего не проверяет.
 */

it('принимает валидный вывод', function () {
    $result = (new AlertOutputValidator())->validate([
        json_encode(['triggered' => true, 'value' => 12, 'message' => 'мало заказов', 'rows' => [['a' => 1]]]),
    ]);

    expect($result['ok'])->toBeTrue()
        ->and($result['triggered'])->toBeTrue()
        ->and($result['value'])->toBe(12)
        ->and($result['message'])->toBe('мало заказов')
        ->and($result['rows'])->toBe([['a' => 1]]);
});

it('заполняет необязательные поля по умолчанию', function () {
    $result = (new AlertOutputValidator())->validate([json_encode(['triggered' => false])]);

    expect($result['ok'])->toBeTrue()
        ->and($result['value'])->toBeNull()
        ->and($result['message'])->toBeNull()
        ->and($result['rows'])->toBe([]);
});

it('считает ошибкой пустой вывод', function () {
    $result = (new AlertOutputValidator())->validate([]);

    expect($result['ok'])->toBeFalse();
});

it('считает ошибкой отсутствие поля triggered', function () {
    $result = (new AlertOutputValidator())->validate([json_encode(['value' => 1])]);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('triggered');
});

it('считает ошибкой нечисловое/нелогическое значение triggered', function () {
    $result = (new AlertOutputValidator())->validate([json_encode(['triggered' => 'yes'])]);

    expect($result['ok'])->toBeFalse();
});

it('считает ошибкой невалидный JSON', function () {
    $result = (new AlertOutputValidator())->validate(['это не json']);

    expect($result['ok'])->toBeFalse();
});

it('считает ошибкой лишний вывод помимо JSON-строки', function () {
    $result = (new AlertOutputValidator())->validate([
        'debug: строка',
        json_encode(['triggered' => true]),
    ]);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('одной строки');
});

it('игнорирует пустые строки вокруг JSON', function () {
    $result = (new AlertOutputValidator())->validate(['', json_encode(['triggered' => true]), '']);

    expect($result['ok'])->toBeTrue();
});
