<?php

namespace App\Helpers\Report;

use App\Models\ScheduledReport;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Когда отчёту уходить в следующий раз.
 *
 * Расписание задаётся в часовом поясе отчёта («каждый понедельник в 9:00» — по часам
 * того, кто его настроил), а в базе время хранится в UTC. Летнее время и переходы поясов
 * Carbon учитывает сам: отчёт уходит в 9:00 по местным часам и до, и после перевода стрелок.
 */
class ReportSchedule
{
    /** День месяца «последний»: в месяцах бывает 28–31 день, поэтому отдельное значение. */
    public const LAST_DAY = 0;

    public static function next(ScheduledReport $report, ?CarbonInterface $after = null): CarbonImmutable
    {
        return self::compute(
            $report->frequency,
            $report->send_time,
            $report->timezone ?: config('reports.default_timezone'),
            $report->weekday,
            $report->day_of_month,
            $after
        );
    }

    public static function compute(
        string $frequency,
        string $time,
        string $timezone,
        ?int $weekday = null,
        ?int $dayOfMonth = null,
        ?CarbonInterface $after = null,
    ): CarbonImmutable {
        [$hour, $minute] = self::parseTime($time);

        $now = CarbonImmutable::instance($after ?? now())->setTimezone($timezone);

        $candidate = match ($frequency) {
            ScheduledReport::DAILY => self::daily($now, $hour, $minute),
            ScheduledReport::WEEKLY => self::weekly($now, $hour, $minute, $weekday ?? 1),
            ScheduledReport::MONTHLY => self::monthly($now, $hour, $minute, $dayOfMonth ?? 1),
            default => throw new InvalidArgumentException("Неизвестная периодичность: {$frequency}"),
        };

        return $candidate->setTimezone('UTC');
    }

    private static function daily(CarbonImmutable $now, int $hour, int $minute): CarbonImmutable
    {
        $candidate = $now->setTime($hour, $minute);

        return $candidate->greaterThan($now) ? $candidate : $candidate->addDay()->setTime($hour, $minute);
    }

    private static function weekly(CarbonImmutable $now, int $hour, int $minute, int $weekday): CarbonImmutable
    {
        // 1 = понедельник … 7 = воскресенье (ISO), как dayOfWeekIso у Carbon.
        $weekday = max(1, min(7, $weekday));

        $candidate = $now->setTime($hour, $minute)->addDays($weekday - $now->dayOfWeekIso);

        return $candidate->greaterThan($now) ? $candidate : $candidate->addWeek()->setTime($hour, $minute);
    }

    private static function monthly(CarbonImmutable $now, int $hour, int $minute, int $dayOfMonth): CarbonImmutable
    {
        $candidate = self::inMonth($now, $hour, $minute, $dayOfMonth);

        if ($candidate->greaterThan($now)) {
            return $candidate;
        }

        return self::inMonth($now->startOfMonth()->addMonth(), $hour, $minute, $dayOfMonth);
    }

    private static function inMonth(CarbonImmutable $month, int $hour, int $minute, int $dayOfMonth): CarbonImmutable
    {
        $first = $month->startOfMonth();

        $day = $dayOfMonth === self::LAST_DAY
            ? $first->daysInMonth
            : max(1, min($dayOfMonth, $first->daysInMonth));

        return $first->setDay($day)->setTime($hour, $minute);
    }

    /** @return array{0:int,1:int} */
    private static function parseTime(string $time): array
    {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            throw new InvalidArgumentException("Время должно быть в формате ЧЧ:ММ, получено «{$time}».");
        }

        return [(int) $m[1], (int) $m[2]];
    }
}
