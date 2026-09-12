<?php

use App\Helpers\DataSource\SqlParameterBinder;

/**
 * Подстановка значений фильтров.
 *
 * Отдельного внимания заслуживает путь без подготовленных выражений: DuckDB
 * выполняется через CLI, параметры туда передать нечем, и значение попадает
 * в текст запроса. Единственная защита там — строгая проверка типа, поэтому
 * она проверяется отдельно.
 */

it('заменяет плейсхолдеры позиционными и выносит значения в привязки', function () {
    $binder = new SqlParameterBinder(supportsBindings: true);

    $result = $binder->apply(
        'SELECT * FROM orders WHERE d >= :date_from AND d <= :date_to',
        ['date_from' => '2024-01-01', 'date_to' => '2024-12-31'],
        ['date_from' => 'date', 'date_to' => 'date']
    );

    expect($result['sql'])->toBe('SELECT * FROM orders WHERE d >= ? AND d <= ?')
        ->and($result['bindings'])->toBe(['2024-01-01', '2024-12-31']);
});

it('не принимает приведение типа PostgreSQL за плейсхолдер', function () {
    $binder = new SqlParameterBinder(supportsBindings: true);

    // col::date — приведение типа, а не параметр. Без защиты регулярка
    // съела бы «:date» и сломала запрос.
    $result = $binder->apply(
        'SELECT created_at::date AS day FROM orders WHERE created_at >= :date_from',
        ['date_from' => '2024-01-01'],
        ['date_from' => 'date']
    );

    expect($result['sql'])->toContain('created_at::date')
        ->and($result['bindings'])->toBe(['2024-01-01']);
});

it('оставляет неизвестные плейсхолдеры нетронутыми', function () {
    $binder = new SqlParameterBinder(supportsBindings: true);

    $result = $binder->apply('SELECT * FROM t WHERE a = :unknown', [], []);

    expect($result['sql'])->toContain(':unknown')
        ->and($result['bindings'])->toBe([]);
});

it('отклоняет дату, не похожую на дату', function () {
    $binder = new SqlParameterBinder(supportsBindings: false);

    // Это и есть защита DuckDB: значение, не прошедшее приведение,
    // до запроса не доходит.
    expect(fn () => $binder->apply(
        'SELECT * FROM t WHERE d >= :day',
        ['day' => "2024-01-01'; DROP TABLE users; --"],
        ['day' => 'date']
    ))->toThrow(RuntimeException::class);
});

it('подставляет значения литералами там, где привязок нет', function () {
    $binder = new SqlParameterBinder(supportsBindings: false);

    $result = $binder->apply(
        'SELECT * FROM t WHERE d >= :day AND n > :n',
        ['day' => '2024-05-01', 'n' => '42'],
        ['day' => 'date', 'n' => 'int']
    );

    expect($result['sql'])->toBe("SELECT * FROM t WHERE d >= '2024-05-01' AND n > 42")
        ->and($result['bindings'])->toBe([]);
});

it('экранирует кавычки в строковом значении', function () {
    $binder = new SqlParameterBinder(supportsBindings: false);

    $result = $binder->apply(
        'SELECT * FROM t WHERE name = :name',
        ['name' => "O'Brien"],
        ['name' => 'string']
    );

    expect($result['sql'])->toBe("SELECT * FROM t WHERE name = 'O''Brien'");
});

it('превращает пустое значение в NULL', function () {
    $binder = new SqlParameterBinder(supportsBindings: false);

    // Идиома «(:date_from IS NULL OR ...)» рассчитана именно на это:
    // период не выбран — условие не применяется.
    $result = $binder->apply(
        'SELECT * FROM t WHERE (:date_from IS NULL OR d >= :date_from)',
        ['date_from' => ''],
        ['date_from' => 'date']
    );

    expect($result['sql'])->toBe('SELECT * FROM t WHERE (NULL IS NULL OR d >= NULL)');
});

it('не трогает двоеточие внутри строкового литерала', function () {
    $binder = new SqlParameterBinder(supportsBindings: true);

    // «:https» здесь — часть значения, а не параметр. Раньше подстановка
    // шла по всему тексту, и условие превращалось в «LIKE '%url?%'»
    // с лишней привязкой: виджет отбирал совсем не то, о чём просили.
    $result = $binder->apply(
        "SELECT * FROM t WHERE url LIKE '%url:https%' AND d >= :date_from",
        ['date_from' => '2024-01-01', 'https' => null],
        ['date_from' => 'date']
    );

    expect($result['sql'])->toBe("SELECT * FROM t WHERE url LIKE '%url:https%' AND d >= ?")
        ->and($result['bindings'])->toBe(['2024-01-01']);
});

it('видит плейсхолдер после литерала с удвоенной кавычкой', function () {
    $binder = new SqlParameterBinder(supportsBindings: true);

    $result = $binder->apply(
        "SELECT 'O''Brien:x' AS a FROM t WHERE d = :day",
        ['day' => '2024-05-01'],
        ['day' => 'date']
    );

    expect($result['sql'])->toBe("SELECT 'O''Brien:x' AS a FROM t WHERE d = ?")
        ->and($result['bindings'])->toBe(['2024-05-01']);
});
