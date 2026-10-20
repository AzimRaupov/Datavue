def main():
    # Просроченные неоплаченные счета: срок оплаты прошёл, а оплачено меньше выставленного.
    rows = query("""
        SELECT i.invoice_no,
               a.account_name,
               i.due_on,
               DATEDIFF(CURDATE(), i.due_on) AS days_overdue,
               i.gross_amount - COALESCE(SUM(p.amount), 0) AS debt
        FROM sls_invoice i
        JOIN sls_contract c ON c.contract_id = i.contract_id
        JOIN crm_account a ON a.account_id = c.account_id
        LEFT JOIN sls_payment p ON p.invoice_id = i.invoice_id
        WHERE i.invoice_status <> 'paid'
          AND i.due_on < CURDATE()
        GROUP BY i.invoice_id, i.invoice_no, a.account_name, i.due_on, i.gross_amount
        ORDER BY days_overdue DESC
        LIMIT 10
    """)

    total = query("""
        SELECT COUNT(*) FROM sls_invoice
        WHERE invoice_status <> 'paid' AND due_on < CURDATE()
    """)[0][0]

    top = [
        {
            "invoice": r[0],
            "client": r[1],
            "due_on": str(r[2]),
            "days_overdue": int(r[3]),
            "debt": float(r[4]),
        }
        for r in rows
    ]

    print(json.dumps({
        "triggered": total > 0,
        "value": total,
        "message": f"Просроченных неоплаченных счетов: {total}",
        "rows": top,
    }, ensure_ascii=False))
