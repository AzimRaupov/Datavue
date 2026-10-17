<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Отчёт: {{ $report->title }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#1c2431;">
    <table role="presentation" width="100%" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e3e7ec;">
        <tr>
            <td style="padding:20px 24px;border-bottom:3px solid #206bc4;">
                <strong style="font-size:16px;">{{ config('app.name') }}</strong>
                <div style="color:#8a94a6;font-size:12px;margin-top:2px;">Отчёт по расписанию</div>
            </td>
        </tr>
        <tr>
            <td style="padding:24px;">
                <h2 style="margin:0 0 12px;font-size:18px;">{{ $report->title }}</h2>

                <p style="margin:0 0 16px;">
                    Дашборд «{{ $dashboardName }}» на {{ $generatedAt->format('d.m.Y H:i') }}
                    (формат {{ $formatLabel }}).
                </p>

                @if($attached)
                    <p style="color:#8a94a6;font-size:13px;margin:0 0 16px;">
                        Отчёт — во вложении к этому письму.
                    </p>
                @elseif($downloadUrl)
                    <p style="margin:0 0 16px;">
                        Файл получился слишком большим для письма, поэтому его можно скачать по ссылке
                        (она действует {{ config('reports.link_ttl_days') }} дн.):
                    </p>
                    <p style="margin:0 0 16px;">
                        <a href="{{ $downloadUrl }}" style="display:inline-block;padding:10px 18px;background:#2fb344;color:#ffffff;text-decoration:none;border-radius:6px;">Скачать отчёт</a>
                    </p>
                @endif

                <a href="{{ $dashboardUrl }}" style="display:inline-block;padding:10px 18px;background:#206bc4;color:#ffffff;text-decoration:none;border-radius:6px;">Открыть дашборд</a>
            </td>
        </tr>
        <tr>
            <td style="padding:16px 24px;border-top:1px solid #e3e7ec;font-size:12px;color:#8a94a6;">
                Письмо отправлено автоматически. Изменить расписание, формат или получателей
                можно на вкладке «Отчёты» рабочего пространства.
            </td>
        </tr>
    </table>
</body>
</html>
