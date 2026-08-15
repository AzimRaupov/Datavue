/**
 * Заготовки кода виджета по семействам.
 *
 * Пустое окно редактора — худшая точка старта: автор должен угадать и контракт
 * рантайма (что доступна функция query, что печатать нужно ровно один JSON),
 * и форму данных своего семейства. Заготовка показывает и то, и другое на
 * рабочем примере, который остаётся только переписать под свои таблицы.
 *
 * Формы взяты из сидеров каталога (database/seeders/Widgets/*) — это те же
 * структуры, которые проверяет WidgetOutputValidator на бэкенде.
 */

const FOOTER = `
    print(json.dumps(result, ensure_ascii=False, default=json_default))`;

const TEMPLATES = {
    "mini-counters": `def main():
    rows = query("""
        SELECT COUNT(*) AS orders, COALESCE(SUM(amount), 0) AS revenue
        FROM orders
    """)

    orders, revenue = rows[0]

    result = {
        "counters": [
            {"name": "Заказов", "value": int(orders)},
            {"name": "Выручка", "value": float(revenue), "suffix": " ₽"},
        ]
    }
${FOOTER}
`,

    "bar": `def main():
    rows = query("""
        SELECT country AS category, SUM(amount) AS value
        FROM orders
        GROUP BY country
        ORDER BY value DESC
        LIMIT 10
    """)

    result = {
        "categories": [row[0] for row in rows],
        "series": [
            {"name": "Выручка", "data": [float(row[1]) for row in rows]}
        ],
    }
${FOOTER}
`,

    "line": `def main():
    rows = query("""
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS label, COUNT(*) AS value
        FROM orders
        GROUP BY label
        ORDER BY label
    """)

    result = {
        "labels": [row[0] for row in rows],
        "series": [
            {"name": "Заказы", "data": [float(row[1]) for row in rows]}
        ],
    }
${FOOTER}
`,

    "pie": `def main():
    rows = query("""
        SELECT channel AS label, SUM(amount) AS value
        FROM orders
        GROUP BY channel
        ORDER BY value DESC
    """)

    result = {
        "labels": [row[0] for row in rows],
        "series": [float(row[1]) for row in rows],
    }
${FOOTER}
`,

    "table": `def main():
    rows = query("""
        SELECT name, country, COUNT(*) AS orders
        FROM customers
        GROUP BY name, country
        ORDER BY orders DESC
        LIMIT 20
    """)

    result = {
        "headers": ["Клиент", "Страна", "Заказов"],
        "rows": [[row[0], row[1], int(row[2])] for row in rows],
    }
${FOOTER}
`,

    "combo": `def main():
    rows = query("""
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS category,
               SUM(amount) AS revenue,
               AVG(margin) AS margin
        FROM orders
        GROUP BY category
        ORDER BY category
    """)

    result = {
        "categories": [row[0] for row in rows],
        "series": [
            {"name": "Выручка", "kind": "column", "data": [float(row[1]) for row in rows]},
            {"name": "Маржа, %", "kind": "line", "data": [float(row[2]) for row in rows]},
        ],
    }
${FOOTER}
`,

    "scatter": `def main():
    rows = query("""
        SELECT price AS x, quantity AS y
        FROM order_items
        LIMIT 200
    """)

    result = {
        "series": [
            {
                "name": "Позиции заказов",
                "data": [{"x": float(row[0]), "y": float(row[1])} for row in rows],
            }
        ]
    }
${FOOTER}
`,

    "treemap": `def main():
    rows = query("""
        SELECT category AS label, SUM(amount) AS value
        FROM orders
        GROUP BY category
        ORDER BY value DESC
        LIMIT 20
    """)

    result = {
        "series": [
            {
                "name": "Выручка",
                "data": [{"x": row[0], "y": float(row[1])} for row in rows],
            }
        ]
    }
${FOOTER}
`,
};

/** Универсальная заготовка, если под семейство своей нет. */
const FALLBACK = `def main():
    rows = query("""
        SELECT country AS label, SUM(amount) AS value
        FROM orders
        GROUP BY country
        ORDER BY value DESC
        LIMIT 10
    """)

    # Соберите result по форме, которая показана справа в «Форме данных».
    result = {
        "labels": [row[0] for row in rows],
        "series": [float(row[1]) for row in rows],
    }
${FOOTER}
`;

export function templateFor(familyName) {
    return TEMPLATES[familyName] ?? FALLBACK;
}
