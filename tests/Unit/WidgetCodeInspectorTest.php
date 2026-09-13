<?php

use App\Helpers\Widget\WidgetCodeInspector;
use Tests\TestCase;

// Инспектор ищет свой python-скрипт через resource_path(), поэтому ему нужно
// поднятое приложение: по умолчанию Pest поднимает его только в Feature.
uses(TestCase::class);

/**
 * Инспектор — единственное, что стоит между формой в браузере и выполнением
 * кода на сервере. Поэтому проверяется не «в целом работает», а каждый способ
 * выбраться за пределы разрешённого.
 */

beforeEach(function () {
    // Разбор идёт настоящим python: без него проверять нечего.
    $binary = base_path('venv/bin/python');

    if (!file_exists($binary)) {
        exec('command -v python3', $output, $exitCode);

        if ($exitCode !== 0) {
            $this->markTestSkipped('Python недоступен — инспектор кода не проверить.');
        }
    }
});

$valid = <<<'PYTHON'
def main():
    rows = query("SELECT country AS label, SUM(amount) AS value FROM orders GROUP BY country")
    result = {
        "labels": [row[0] for row in rows],
        "series": [float(row[1]) for row in rows],
    }
    print(json.dumps(result, ensure_ascii=False, default=json_default))
PYTHON;

it('пропускает корректный код виджета', function () use ($valid) {
    $result = (new WidgetCodeInspector())->inspect($valid);

    expect($result['ok'])->toBeTrue()
        ->and($result['errors'])->toBe([]);
});

it('отклоняет импорт неразрешённого модуля', function () {
    $result = (new WidgetCodeInspector())->inspect(<<<'PYTHON'
import os

def main():
    print(json.dumps({"labels": [], "series": []}))
PYTHON);

    expect($result['ok'])->toBeFalse()
        ->and(implode(' ', $result['errors']))->toContain('os');
});

it('отклоняет чтение файлов', function () {
    $result = (new WidgetCodeInspector())->inspect(<<<'PYTHON'
def main():
    secret = open("/etc/passwd").read()
    print(json.dumps({"labels": [secret], "series": [1]}))
PYTHON);

    expect($result['ok'])->toBeFalse()
        ->and(implode(' ', $result['errors']))->toContain('open');
});

it('отклоняет выполнение строк', function () {
    $result = (new WidgetCodeInspector())->inspect(<<<'PYTHON'
def main():
    eval("1 + 1")
    print(json.dumps({}))
PYTHON);

    expect($result['ok'])->toBeFalse()
        ->and(implode(' ', $result['errors']))->toContain('eval');
});

it('отклоняет обход через служебные атрибуты', function () {
    // Классический побег из белого списка модулей: до subprocess добираются
    // не импортом, а через дерево классов.
    $result = (new WidgetCodeInspector())->inspect(<<<'PYTHON'
def main():
    victim = "".__class__.__mro__[1].__subclasses__()
    print(json.dumps({"labels": [], "series": []}))
PYTHON);

    expect($result['ok'])->toBeFalse();
});

it('требует функцию main без аргументов', function () {
    $result = (new WidgetCodeInspector())->inspect(<<<'PYTHON'
def build(rows):
    print(json.dumps({"labels": [], "series": []}))
PYTHON);

    expect($result['ok'])->toBeFalse()
        ->and(implode(' ', $result['errors']))->toContain('main');
});

it('требует печать результата', function () {
    $result = (new WidgetCodeInspector())->inspect(<<<'PYTHON'
def main():
    result = {"labels": [], "series": []}
PYTHON);

    expect($result['ok'])->toBeFalse()
        ->and(implode(' ', $result['errors']))->toContain('print');
});

it('сообщает о синтаксической ошибке номером строки', function () {
    // Сигнатура main на месте — значит быстрые проверки пройдены и ошибку
    // ловит уже разбор дерева.
    $result = (new WidgetCodeInspector())->inspect(<<<'PYTHON'
def main():
    result = {"labels": [,
    print(json.dumps(result))
PYTHON);

    expect($result['ok'])->toBeFalse()
        ->and(implode(' ', $result['errors']))->toContain('строке');
});

it('сообщает, что функции main нет, не поднимая python', function () {
    $result = (new WidgetCodeInspector())->inspect("def main()\n    print(json.dumps({}))\n");

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toBe(['В коде нет функции «def main():» без аргументов.']);
});

it('отклоняет пустой код, не поднимая python', function () {
    $result = (new WidgetCodeInspector())->inspect('   ');

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toBe(['Код виджета пуст.']);
});
