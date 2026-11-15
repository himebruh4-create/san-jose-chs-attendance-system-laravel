<?php

namespace App\Domain\Attendance;

/**
 * The four time columns of the Daily / Weekly / Monthly report rows and
 * their printouts (AM Arrival, AM Departure, PM Arrival, PM Departure),
 * laid out the way the DTR lays them out (rulesCells in public/js/
 * dtr-view.js; keep the two the same):
 *
 *   - each column shows the kiosk scan when there is one, otherwise the
 *     time of that day's attendance adjustment, otherwise nothing ("—");
 *   - each time says where it came from ('scan' or 'adjustment'), so the
 *     report can mark adjusted times ("adj");
 *   - shift personnel (an afternoon or overnight schedule, or one with its
 *     own configured break) show only their IN and OUT, in the DTR's
 *     columns: 6-2 in AM Arrival / AM Departure, 2-10 in PM Arrival /
 *     PM Departure, 10-6 in PM Arrival / AM Departure (the OUT the next
 *     morning). Regular personnel show each column as recorded.
 *
 * Display only: the day's status (Present, Late, ...) is decided by
 * getTeacherDayStatus() and is not affected.
 */
class ReportTimes
{
    public const COLUMNS = ['am_arrival', 'am_departure', 'pm_arrival', 'pm_departure'];

    public const SCAN = 'scan';

    public const ADJUSTMENT = 'adjustment';

    /**
     * @param  ?array<string, ?string>  $scans  the day's attendance row (kiosk scans)
     * @param  ?array<string, ?string>  $adjustment  the day's attendance adjustment
     * @param  ?array{time_in: string, time_out: string}  $schedule  the person's schedule that weekday
     * @param  array  $breakConfig  BreakConfig::load()
     * @return array<string, ?array{time: string, source: string}>
     */
    public static function cells(?array $scans, ?array $adjustment, ?array $schedule, array $breakConfig): array
    {
        $merged = [];
        $sources = [];

        foreach (self::COLUMNS as $column) {
            $scan = dtrClockOrEmpty($scans[$column] ?? '');
            $adjusted = dtrClockOrEmpty($adjustment[$column] ?? '');

            $merged[$column] = $scan !== '' ? $scan : $adjusted;
            $sources[$column] = $scan !== '' ? self::SCAN : ($adjusted !== '' ? self::ADJUSTMENT : null);
        }

        $cells = array_fill_keys(self::COLUMNS, null);

        if (! dtrIsShiftPersonnel($breakConfig, $schedule)) {
            foreach (self::COLUMNS as $column) {
                if ($merged[$column] !== '') {
                    $cells[$column] = ['time' => $merged[$column], 'source' => $sources[$column]];
                }
            }

            return $cells;
        }

        // Shift personnel: the actual IN and OUT only, in the DTR's columns.
        [$in, $out] = dtrInOutOf($merged, $adjustment);
        $display = dtrShiftColumns($schedule);

        if ($in !== '') {
            $from = $merged['am_arrival'] === $in ? 'am_arrival' : 'pm_arrival';
            $cells[$display['in']] = ['time' => $in, 'source' => $sources[$from]];
        }

        if ($out !== '') {
            $from = $merged['pm_departure'] === $out ? 'pm_departure' : 'am_departure';
            $cells[$display['out']] = ['time' => $out, 'source' => $sources[$from]];
        }

        return $cells;
    }
}
