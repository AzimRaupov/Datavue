<?php

use App\Helpers\Report\ReportSchedule;
use Carbon\CarbonImmutable;

function nextRun(string $frequency, string $time, string $tz, ?int $weekday, ?int $day, string $after): string
{
    return ReportSchedule::compute($frequency, $time, $tz, $weekday, $day, CarbonImmutable::parse($after, 'UTC'))
        ->format('Y-m-d H:i');
}

it('считает ежедневную отправку по часам часового пояса отчёта', function () {
    // 09:00 в Душанбе (UTC+5) — это 04:00 UTC; в субботу в 12:00 UTC сегодняшнее время уже прошло.
    expect(nextRun('daily', '09:00', 'Asia/Dushanbe', null, null, '2026-10-17 12:00'))->toBe('2026-10-18 04:00');
});

it('отправляет сегодня, если время ещё не наступило', function () {
    expect(nextRun('daily', '15:00', 'UTC', null, null, '2026-10-17 12:00'))->toBe('2026-10-17 15:00');
});

it('считает еженедельную отправку по дню недели', function () {
    // 2026-10-17 — суббота. Понедельник — ближайший, суббота 09:00 уже прошла — следующая неделя.
    expect(nextRun('weekly', '09:00', 'UTC', 1, null, '2026-10-17 12:00'))->toBe('2026-10-19 09:00');
    expect(nextRun('weekly', '09:00', 'UTC', 6, null, '2026-10-17 12:00'))->toBe('2026-10-24 09:00');
    expect(nextRun('weekly', '15:00', 'UTC', 6, null, '2026-10-17 12:00'))->toBe('2026-10-17 15:00');
    expect(nextRun('weekly', '09:00', 'UTC', 7, null, '2026-10-17 12:00'))->toBe('2026-10-18 09:00');
});

it('считает ежемесячную отправку по числу месяца', function () {
    expect(nextRun('monthly', '09:00', 'UTC', null, 1, '2026-10-17 12:00'))->toBe('2026-11-01 09:00');
    expect(nextRun('monthly', '09:00', 'UTC', null, 28, '2026-10-17 12:00'))->toBe('2026-10-28 09:00');
    // Декабрь → январь следующего года.
    expect(nextRun('monthly', '09:00', 'UTC', null, 28, '2026-12-28 10:00'))->toBe('2027-01-28 09:00');
});

it('умеет «последний день месяца» в коротких и длинных месяцах', function () {
    expect(nextRun('monthly', '09:00', 'UTC', null, ReportSchedule::LAST_DAY, '2027-02-10 00:00'))->toBe('2027-02-28 09:00');
    expect(nextRun('monthly', '09:00', 'UTC', null, ReportSchedule::LAST_DAY, '2028-02-10 00:00'))->toBe('2028-02-29 09:00');
    expect(nextRun('monthly', '09:00', 'UTC', null, ReportSchedule::LAST_DAY, '2026-10-31 10:00'))->toBe('2026-11-30 09:00');
});

it('держит местное время при переходе на зимнее время', function () {
    // В Берлине 25.10.2026 стрелки переводят назад: 09:00 до перевода — 07:00 UTC, после — 08:00 UTC.
    expect(nextRun('weekly', '09:00', 'Europe/Berlin', 6, null, '2026-10-20 00:00'))->toBe('2026-10-24 07:00');
    expect(nextRun('weekly', '09:00', 'Europe/Berlin', 7, null, '2026-10-20 00:00'))->toBe('2026-10-25 08:00');
});

it('не принимает неверное время и неизвестную периодичность', function () {
    expect(fn () => nextRun('daily', '25:99', 'UTC', null, null, '2026-10-17 12:00'))->toThrow(InvalidArgumentException::class);
    expect(fn () => nextRun('hourly', '09:00', 'UTC', null, null, '2026-10-17 12:00'))->toThrow(InvalidArgumentException::class);
});
