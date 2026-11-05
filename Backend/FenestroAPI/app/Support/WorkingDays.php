<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Working-day arithmetic on LOCAL calendar dates (callers convert to the business time zone first).
 * A working day is any day whose weekday is not in $weeklyOff and that is not a company holiday
 * (Action Hub → Working calendar, table action_hub_holidays). Public holidays are added there.
 */
final class WorkingDays
{
    /** @param int[] $weeklyOff 0 = Sunday … 6 = Saturday */
    public static function isWorkingDay(CarbonInterface $date, array $weeklyOff): bool
    {
        return ! in_array($date->dayOfWeek, $weeklyOff, true)
            && ! array_key_exists($date->format('Y-m-d'), ActionHub::holidays());
    }

    /** Working days after $from up to and including $to (0 when $to is on or before $from). */
    public static function countBetween(CarbonInterface $from, CarbonInterface $to, array $weeklyOff): int
    {
        $day = CarbonImmutable::instance($from)->startOfDay();
        $end = CarbonImmutable::instance($to)->startOfDay();
        $n = 0;
        for ($guard = 0; $day->lt($end) && $guard < 3700; $guard++) {
            $day = $day->addDay();
            if (self::isWorkingDay($day, $weeklyOff)) {
                $n++;
            }
        }

        return $n;
    }

    /** The date $n working days after $date ($date itself when $n = 0). */
    public static function add(CarbonInterface $date, int $n, array $weeklyOff): CarbonImmutable
    {
        $day = CarbonImmutable::instance($date)->startOfDay();
        for ($found = 0, $guard = 0; $found < $n && $guard < 3700; $guard++) {
            $day = $day->addDay();
            if (self::isWorkingDay($day, $weeklyOff)) {
                $found++;
            }
        }

        return $day;
    }
}
