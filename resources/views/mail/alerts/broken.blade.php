<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>{{ $disabled ? 'Алерт отключён' : 'Ошибка проверки алерта' }}: {{ $alert->title }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#1c2431;">
    <table role="presentation" width="100%" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e3e7ec;">
        <tr>
            <td style="padding:20px 24px;border-bottom:3px solid #f76707;">
                <strong style="font-size:16px;">{{ config('app.name') }}</strong>
                <div style="color:#8a94a6;font-size:12px;margin-top:2px;">
                    {{ $disabled ? 'Алерт отключён' : 'Ошибка проверки' }}
                </div>
            </td>
        </tr>
        <tr>
            <td style="padding:24px;">
                <h2 style="margin:0 0 12px;font-size:18px;">{{ $alert->title }}</h2>

                @if($disabled)
                    <p style="margin:0 0 16px;">
                        Алерт выключен автоматически: {{ config('alerts.disable_after_failures') }}
                        проверок подряд закончились ошибкой. Включить его снова можно
                        в рабочем пространстве после того, как условие будет исправлено.
                    </p>
                @else
                    <p style="margin:0 0 16px;">
                        Проверка алерта не смогла выполниться. Это не значит, что условие
                        не выполнено, — платформа не смогла его проверить.
                    </p>
                @endif

                <p style="margin:0 0 16px;color:#8a94a6;">
                    Проверено: {{ $check->checking_at->format('d.m.Y H:i') }}
                </p>

                @if($check->error)
                    <pre style="background:#f4f6f8;padding:12px;border-radius:6px;font-size:12px;white-space:pre-wrap;word-break:break-word;">{{ $check->error }}</pre>
                @endif

                <a href="{{ $workspaceUrl }}" style="display:inline-block;padding:10px 18px;background:#206bc4;color:#ffffff;text-decoration:none;border-radius:6px;margin-top:8px;">Открыть в рабочем пространстве</a>
            </td>
        </tr>
        <tr>
            <td style="padding:16px 24px;border-top:1px solid #e3e7ec;font-size:12px;color:#8a94a6;">
                Письмо отправлено автоматически проверкой алерта.
            </td>
        </tr>
    </table>
</body>
</html>
