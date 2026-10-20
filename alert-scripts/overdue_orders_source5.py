def main():
    # Просроченная дебиторка: заказы со статусом оплаты 'overdue' и остаток долга по ним.
    rows = query("""
        SELECT o.order_number,
               c.name,
               DATE_ADD(o.order_date, INTERVAL o.payment_terms_days DAY) AS due_date,
               DATEDIFF(CURDATE(), DATE_ADD(o.order_date, INTERVAL o.payment_terms_days DAY)) AS days_overdue,
               o.total_amount_tjs - COALESCE(SUM(p.amount_tjs), 0) AS debt
        FROM sales_orders o
        JOIN customers c ON c.customer_id = o.customer_id
        LEFT JOIN payments p ON p.order_id = o.order_id
        WHERE o.payment_status = 'overdue'
          AND o.status <> 'cancelled'
        GROUP BY o.order_id, o.order_number, c.name, o.order_date,
                 o.payment_terms_days, o.total_amount_tjs
        ORDER BY debt DESC
        LIMIT 10
    """)

    total, total_debt = query("""
        SELECT COUNT(*),
               COALESCE(SUM(o.total_amount_tjs - COALESCE(p.paid, 0)), 0)
        FROM sales_orders o
        LEFT JOIN (
            SELECT order_id, SUM(amount_tjs) AS paid FROM payments GROUP BY order_id
        ) p ON p.order_id = o.order_id
        WHERE o.payment_status = 'overdue' AND o.status <> 'cancelled'
    """)[0]

    top = [
        {
            "order": r[0],
            "customer": r[1],
            "due_date": str(r[2]),
            "days_overdue": int(r[3]),
            "debt_tjs": float(r[4]),
        }
        for r in rows
    ]

    print(json.dumps({
        "triggered": total > 0,
        "value": total,
        "message": f"Просроченных заказов: {total}, общий долг: {float(total_debt):,.2f} TJS",
        "rows": top,
    }, ensure_ascii=False))
