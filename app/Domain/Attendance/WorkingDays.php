<?php

namespace App\Domain\Attendance;

use Illuminate\Support\Facades\DB;

/**
 * Which days are working days, from the personnel's own schedules, never
 * a fixed school-wide Monday-to-Friday / Saturday rule. One rule for the
 * Monthly Report calendar and its day window, the Daily Report, the
 * Weekly Roster and the dashboards:
 *
 *   - a day is a working day when at least one active personnel is
 *     scheduled on its weekday (teacher_schedules); Saturdays and Sundays
 *     included;
 *   - otherwise it is "No work" (Sundays too, unless someone is scheduled
 *     on Sundays), except a holiday / School Schedule Change, which keeps
 *     its own label;
 *   - for one person, a day is a working day when THAT person is
 *     scheduled on its weekday; getTeacherDayStatus() gives the day
 *     "Not Scheduled" otherwise, which is never counted (never Absent).
 *
 * Weeks and the calendar run Monday to Saturday, plus Sunday when anyone is
 * scheduled on Sundays.
 */
class WorkingDays
{
    public const NO_WORK = 'No work';

    /** @param  list<string>  $weekdays  lower-case weekday names someone is scheduled on */
    public function __construct(private array $weekdays) {}

    /** The weekdays active personnel are scheduled on (one query). */
    public static function load(): self
    {
        return new self(DB::table('teacher_schedules as s')
            ->join('teachers as t', fn ($join) => $join->on('t.id', '=', 's.teacher_id')->where('t.is_deleted', 0))
            ->distinct()
            ->pluck('s.day')
            ->map(fn ($day) => strtolower((string) $day))
            ->unique()
            ->values()
            ->all());
    }

    /** At least one active personnel is scheduled on this date's weekday. */
    public function isWorkingDay(string $date): bool
    {
        return in_array(strtolower(date('l', strtotime($date))), $this->weekdays, true);
    }

    /** Whether weeks and the calendar include Sunday (someone is scheduled on it). */
    public function includesSunday(): bool
    {
        return in_array('sunday', $this->weekdays, true);
    }

    /** The day columns of a week, Monday first: Mon–Sat, plus Sun when anyone works Sundays. */
    public function daysPerWeek(): int
    {
        return $this->includesSunday() ? 7 : 6;
    }

    /**
     * Monday and the last shown day (Saturday, or Sunday) of the week
     * containing $anyDay.
     *
     * @return array{0: string, 1: string}
     */
    public function weekRange(string $anyDay): array
    {
        $monday = strtotime('monday this week', strtotime($anyDay));

        return [date('Y-m-d', $monday), date('Y-m-d', strtotime('+'.($this->daysPerWeek() - 1).' days', $monday))];
    }

    /** True when the date is one of the shown days (Sunday is shown only when someone works it). */
    public function isShown(string $date): bool
    {
        return date('N', strtotime($date)) < 7 || $this->includesSunday();
    }

    /**
     * A day's label for everyone: the whole-day School Schedule Change's
     * own label, else "No work" when nobody is scheduled, else null.
     */
    public function label(string $date, ?array $wholeDayEvent): ?string
    {
        if ($wholeDayEvent !== null) {
            return ScheduleChanges::shortLabel($wholeDayEvent);
        }

        return $this->isWorkingDay($date) ? null : self::NO_WORK;
    }
}
