<?php

namespace App\Domain\Attendance;

use Illuminate\Support\Facades\DB;

/**
 * The School Lunch Break and the schedule-specific paid breaks
 * (system_settings.lunch_out / lunch_in / schedule_breaks).
 *
 * load() returns exactly what dtrLoadBreakConfig() returned in the
 * native-PHP system, so the rule functions in rules.php take it as is.
 */
class BreakConfig
{
    /** @return array<int, array{time_in: string, time_out: string, break_start: string, break_end: string}> */
    public static function defaultEntries(): array
    {
        return dtrDefaultBreakEntries();
    }

    /** @return array{lunch_out: string, lunch_in: string, entries: array} */
    public static function load(): array
    {
        $cfg = ['lunch_out' => '12:15:00', 'lunch_in' => '13:00:00', 'entries' => dtrDefaultBreakEntries()];

        $row = DB::table('system_settings')->where('id', 1)->first();

        if ($row) {
            if (! empty($row->lunch_out)) {
                $cfg['lunch_out'] = $row->lunch_out;
            }
            if (! empty($row->lunch_in)) {
                $cfg['lunch_in'] = $row->lunch_in;
            }

            if (! empty($row->schedule_breaks)) {
                $decoded = json_decode($row->schedule_breaks, true);
                if (is_array($decoded)) {
                    $entries = [];
                    foreach ($decoded as $e) {
                        $ti = dtrClockOrEmpty($e['time_in'] ?? '');
                        $to = dtrClockOrEmpty($e['time_out'] ?? '');
                        $bs = dtrClockOrEmpty($e['break_start'] ?? '');
                        $be = dtrClockOrEmpty($e['break_end'] ?? '');
                        if ($ti && $to && $bs && $be) {
                            $entries[] = ['time_in' => $ti, 'time_out' => $to, 'break_start' => $bs, 'break_end' => $be];
                        }
                    }
                    $cfg['entries'] = $entries;
                }
            }
        }

        return $cfg;
    }
}
