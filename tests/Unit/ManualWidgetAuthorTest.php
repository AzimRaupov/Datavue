<?php

use App\Helpers\Widget\ManualWidgetAuthor;
use App\Models\DashboardWidget;

/**
 * Регресс: у счётчиков query_spec хранит несколько именных запросов —
 * по одному на карточку. Редактор SQL показывает только первый
 * (WidgetSpecValidator::primaryQueryOf()), и если сохранение вслепую
 * пересобирает спецификацию из текста этого поля, «открыл редактор и сразу
 * нажал сохранить» стирает все запросы, кроме первого.
 */
function nextQuerySpec(DashboardWidget $widget, string $family, string $sql, array $presentation = []): array
{
    $method = new ReflectionMethod(ManualWidgetAuthor::class, 'nextQuerySpec');
    $method->setAccessible(true);

    return $method->invoke(new ManualWidgetAuthor(), $widget, $family, $sql, $presentation);
}

it('не трогает остальные запросы счётчика, если автор не менял SQL', function () {
    $widget = new DashboardWidget();
    $widget->query_spec = [
        'queries' => [
            'clients' => "SELECT 'Клиентов' AS name, COUNT(*) AS value FROM customers",
            'orders' => "SELECT 'Заказов' AS name, COUNT(*) AS value FROM orders",
            'revenue' => "SELECT 'Выручка' AS name, SUM(quantityOrdered * priceEach) AS value FROM orderdetails",
        ],
        'shape' => 'counters',
    ];

    // То же самое, что редактор показал бы в поле SQL — первый запрос.
    $shown = "SELECT 'Клиентов' AS name, COUNT(*) AS value FROM customers";

    $spec = nextQuerySpec($widget, 'mini-counters', $shown);

    expect($spec['queries'])->toHaveCount(3)
        ->and($spec['queries'])->toBe($widget->query_spec['queries']);
});

it('заменяет все запросы одним, когда автор их правда переписал', function () {
    $widget = new DashboardWidget();
    $widget->query_spec = [
        'queries' => [
            'clients' => "SELECT 'Клиентов' AS name, COUNT(*) AS value FROM customers",
            'orders' => "SELECT 'Заказов' AS name, COUNT(*) AS value FROM orders",
        ],
        'shape' => 'counters',
    ];

    $edited = "SELECT 'Клиентов' AS name, COUNT(*) AS value FROM customers WHERE active = 1";

    $spec = nextQuerySpec($widget, 'mini-counters', $edited);

    expect($spec['queries'])->toBe(['main' => $edited]);
});

it('переносит оформление, даже когда запросы не тронуты', function () {
    $widget = new DashboardWidget();
    $widget->query_spec = [
        'queries' => [
            'clients' => "SELECT 'Клиентов' AS name, COUNT(*) AS value FROM customers",
            'orders' => "SELECT 'Заказов' AS name, COUNT(*) AS value FROM orders",
        ],
        'shape' => 'counters',
    ];

    $shown = "SELECT 'Клиентов' AS name, COUNT(*) AS value FROM customers";

    $spec = nextQuerySpec($widget, 'mini-counters', $shown, ['counters' => [['prefix' => '$']]]);

    expect($spec['queries'])->toHaveCount(2)
        ->and($spec['presentation'])->toBe(['counters' => [['prefix' => '$']]]);
});

it('собирает спецификацию заново для одиночного запроса', function () {
    $widget = new DashboardWidget();
    $widget->query_spec = ['queries' => ['main' => 'SELECT 1 AS value'], 'shape' => 'series_matrix'];

    $spec = nextQuerySpec($widget, 'bar', 'SELECT 2 AS value');

    expect($spec['queries'])->toBe(['main' => 'SELECT 2 AS value']);
});
