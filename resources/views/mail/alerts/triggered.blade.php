<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Алерт сработал: {{ $alert->title }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#1c2431;">
    <table role="presentation" width="100%" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e3e7ec;">
        <tr>
            <td style="padding:20px 24px;border-bottom:3px solid #d63939;">
                <strong style="font-size:16px;">{{ config('app.name') }}</strong>
                <div style="color:#8a94a6;font-size:12px;margin-top:2px;">Алерт сработал</div>
            </td>
        </tr>
        <tr>
            <td style="padding:24px;">
                <h2 style="margin:0 0 12px;font-size:18px;">{{ $alert->title }}</h2>

                @if($alert->description)
                    <p style="color:#576076;margin:0 0 16px;">{{ $alert->description }}</p>
                @endif

                @if($check->message)
                    <p style="margin:0 0 16px;">{{ $check->message }}</p>
                @endif

                <table role="presentation" style="width:100%;border-collapse:collapse;margin-bottom:16px;">
                    <tr>
                        <td style="padding:6px 0;color:#8a94a6;width:160px;">Проверено</td>
                        <td style="padding:6px 0;">{{ $check->checking_at->format('d.m.Y H:i') }}</td>
                    </tr>
                    @if($check->value !== null)
                        <tr>
                            <td style="padding:6px 0;color:#8a94a6;">Значение</td>
                            <td style="padding:6px 0;"><strong>{{ $check->value }}</strong></td>
                        </tr>
                    @endif
                    @if($check->matched_rows !== null)
                        <tr>
                            <td style="padding:6px 0;color:#8a94a6;">Строк в результате</td>
                            <td style="padding:6px 0;">{{ $check->matched_rows }}</td>
                        </tr>
                    @endif
                </table>

                @if(!empty($check->payload))
                    <div style="font-size:12px;color:#8a94a6;margin-bottom:6px;">Образец данных:</div>
                    <table role="presentation" style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px;">
                        <tr>
                            @foreach(array_keys($check->payload[0]) as $column)
                                <th style="text-align:left;padding:6px 8px;background:#f4f6f8;border:1px solid #e3e7ec;">{{ $column }}</th>
                            @endforeach
                        </tr>
                        @foreach(array_slice($check->payload, 0, 20) as $row)
                            <tr>
                                @foreach($row as $value)
                                    <td style="padding:6px 8px;border:1px solid #e3e7ec;">{{ is_scalar($value) ? $value : json_encode($value) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </table>
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
