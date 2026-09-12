<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Алерт вернулся в норму: {{ $alert->title }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#1c2431;">
    <table role="presentation" width="100%" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e3e7ec;">
        <tr>
            <td style="padding:20px 24px;border-bottom:3px solid #2fb344;">
                <strong style="font-size:16px;">{{ config('app.name') }}</strong>
                <div style="color:#8a94a6;font-size:12px;margin-top:2px;">Алерт вернулся в норму</div>
            </td>
        </tr>
        <tr>
            <td style="padding:24px;">
                <h2 style="margin:0 0 12px;font-size:18px;">{{ $alert->title }}</h2>

                <p style="margin:0 0 16px;">
                    Условие больше не выполняется. Последняя проверка —
                    {{ $check->checking_at->format('d.m.Y H:i') }}.
                    @if($check->value !== null)
                        Текущее значение: <strong>{{ $check->value }}</strong>.
                    @endif
                </p>

                @if($check->csv_path)
                    <p style="color:#8a94a6;font-size:13px;margin:0 0 12px;">
                        Полный результат проверки ({{ $check->csv_row_count }} стр.) — во вложении CSV.
                    </p>
                @endif

                <a href="{{ $workspaceUrl }}" style="display:inline-block;padding:10px 18px;background:#206bc4;color:#ffffff;text-decoration:none;border-radius:6px;">Открыть в рабочем пространстве</a>
            </td>
        </tr>
        <tr>
            <td style="padding:16px 24px;border-top:1px solid #e3e7ec;font-size:12px;color:#8a94a6;">
                Письмо отправлено автоматически проверкой алерта. Изменить расписание
                или получателей можно в рабочем пространстве.
            </td>
        </tr>
    </table>
</body>
</html>
