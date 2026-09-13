/**
 * Заготовки запросов по семействам виджетов.
 *
 * Контракт простой: аналитик пишет ОДИН запрос и называет колонки так, как
 * ждёт его семейство. Раскладку по форме виджета — ряды, ось, заполнение
 * пропусков нулями — делает платформа, а не автор запроса.
 *
 * Имена колонок в заготовках совпадают с тем, что проверяет
 * WidgetSpecValidator на сервере: список required_columns приходит с виджетом,
 * поэтому подсказка в редакторе и проверка при сохранении не разъезжаются.
 */

const TEMPLATES = {
    // name, value — по строке на плитку. Несколько метрик собираются UNION ALL:
    // одна выборка вместо нескольких запросов.
    "mini-counters": `SELECT 'Заказов' AS name, COUNT(*) AS value
FROM orders
UNION ALL
SELECT 'Клиентов', COUNT(*)
FROM customers
UNION ALL
SELECT 'Выручка', ROUND(SUM(amount), 0)
FROM orders`,

    // series, category, value. series — то, что сравнивают между собой
    // (линии или цвета столбцов), category — деления оси.
    "bar": `SELECT 'Выручка' AS series,
       country     AS category,
       SUM(amount) AS value
FROM orders
GROUP BY country
ORDER BY value DESC
LIMIT 10`,

    "line": `SELECT 'Заказы' AS series,
       DATE_FORMAT(created_at, '%Y-%m') AS category,
       COUNT(*) AS value
FROM orders
GROUP BY category
ORDER BY category`,

    "combo": `SELECT metric AS series, category, value
FROM (
    SELECT 'Выручка' AS metric,
           DATE_FORMAT(created_at, '%Y-%m') AS category,
           SUM(amount) AS value
    FROM orders
    GROUP BY category

    UNION ALL

    SELECT 'Заказы',
           DATE_FORMAT(created_at, '%Y-%m'),
           COUNT(*)
    FROM orders
    GROUP BY 2
) t
ORDER BY category, series`,

    "radar": `SELECT 'Отдел A' AS series,
       skill        AS category,
       AVG(score)   AS value
FROM assessments
GROUP BY skill`,

    "heatmap": `SELECT WEEKDAY(created_at) AS series,
       HOUR(created_at)    AS category,
       COUNT(*)            AS value
FROM orders
GROUP BY series, category`,

    // label, value — плоский список.
    "pie": `SELECT status      AS label,
       COUNT(*)    AS value
FROM orders
GROUP BY status
ORDER BY value DESC`,

    "radial": `SELECT 'Выполнение плана' AS label,
       ROUND(100 * SUM(amount) / 1000000) AS value
FROM orders`,

    "funnel": `SELECT stage       AS label,
       COUNT(*)    AS value
FROM leads
GROUP BY stage
ORDER BY value DESC`,

    "treemap": `SELECT category    AS label,
       SUM(amount) AS value
FROM orders
GROUP BY category
ORDER BY value DESC
LIMIT 20`,

    "map": `SELECT country_code AS label,
       SUM(amount)  AS value
FROM orders
GROUP BY country_code`,

    // series, x, y — точки. Для пузырьков добавляется z.
    "scatter": `SELECT 'Позиции заказов' AS series,
       price    AS x,
       quantity AS y
FROM order_items
LIMIT 500`,

    // Таблица — исключение: контракта на имена нет, заголовки берутся
    // из псевдонимов, поэтому подписи правятся прямо в запросе.
    "table": `SELECT c.name    AS "Клиент",
       c.country AS "Страна",
       COUNT(o.id) AS "Заказов"
FROM customers c
LEFT JOIN orders o ON o.customer_id = c.id
GROUP BY c.name, c.country
ORDER BY 3 DESC
LIMIT 20`,
};

const FALLBACK = `SELECT label, value
FROM your_table
LIMIT 10`;

export function queryTemplateFor(familyName) {
    return TEMPLATES[familyName] ?? FALLBACK;
}

/**
 * Пояснение к контракту колонок — показывается рядом с редактором.
 * Ключ — имя колонки, значение — что она означает.
 */
export const COLUMN_HINTS = {
    series: "что сравниваем: линия, цвет столбца, ряд легенды",
    category: "деления оси — то, по чему идёт сравнение",
    label: "подпись сегмента",
    value: "число, которое рисуем",
    name: "название метрики на плитке",
    x: "координата по горизонтали",
    y: "координата по вертикали",
    z: "размер пузырька",
    percent: "процент выполнения",
};
