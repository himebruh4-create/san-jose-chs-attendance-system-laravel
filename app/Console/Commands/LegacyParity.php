<?php

namespace App\Console\Commands;

use App\Domain\Attendance\AttendanceData;
use App\Domain\Attendance\BreakConfig;
use App\Domain\Attendance\MonthlySummary;
use App\Domain\Attendance;
use App\Domain\Attendance\PendingReview;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use mysqli;

/**
 * Differential test: loads the ORIGINAL native-PHP rule code (global
 * functions, reading the legacy database through mysqli) next to the
 * ported Laravel code (namespaced functions + services, reading the
 * application database) and compares their answers on the real data:
 *
 *   - getTeacherDayStatus / getTeacherDayHours for every person and day
 *   - dtrRouteScan for every person, day and a grid of scan times
 *   - the Pending Review queue and its period grouping
 *   - the Monthly Summary for every month with data
 *
 * Both databases must hold the same data (run legacy:import first).
 */
class LegacyParity extends Command
{
    protected $signature = 'legacy:parity
        {--code= : Path to the native-PHP project (default: LEGACY_CODE_PATH or ../san-jose-chs-attendance-system)}
        {--from=2026-05-01 : First date to compare}
        {--to= : Last date to compare (default: end of next month)}';

    protected $description = 'Compare the ported attendance rules with the original native-PHP code on the real data';

    private int $checks = 0;

    private array $failures = [];

    public function handle(): int
    {
        $code = rtrim($this->option('code') ?: config('database.legacy_code_path'), '/\\');

        if (! is_file($code.'/dtr/report-core.php')) {
            $this->error("Native-PHP code not found at {$code}");

            return self::FAILURE;
        }

        // The original code reads a global mysqli $conn.
        $legacy = config('database.connections.legacy');
        $GLOBALS['conn'] = new mysqli($legacy['host'], $legacy['username'], $legacy['password'], $legacy['database'], (int) $legacy['port']);
        $GLOBALS['conn']->set_charset('utf8mb4');
        $conn = $GLOBALS['conn'];

        require_once $code.'/dtr/report-core.php';   // pulls in review-core, dtr-core, attendance-rules

        $from = $this->option('from');
        $to = $this->option('to') ?: date('Y-m-t', strtotime('first day of next month'));

        $this->info("Comparing {$from} to {$to}");

        $this->compareDays($conn, $from, $to);
        $this->compareScanRouting($conn, $from, $to);
        $this->comparePendingReview($conn);
        $this->compareMonthlySummaries($conn, $from, $to);

        $this->newLine();

        if ($this->failures) {
            $this->error(count($this->failures).' of '.$this->checks.' checks differ:');
            foreach (array_slice($this->failures, 0, 40) as $f) {
                $this->line('  - '.$f);
            }

            return self::FAILURE;
        }

        $this->info("All {$this->checks} checks match the native-PHP code.");

        return self::SUCCESS;
    }

    private function same(string $label, mixed $old, mixed $new): void
    {
        $this->checks++;

        if ($old !== $new) {
            $this->failures[] = $label.': old='.json_encode($old).' new='.json_encode($new);
        }
    }

    /** Status and hours of every person on every date. */
    private function compareDays(mysqli $conn, string $from, string $to): void
    {
        $oldEvents = dtrLoadActiveEvents($conn);
        $newEvents = AttendanceData::activeEvents();
        $this->same('active events', $this->normalize($oldEvents), $this->normalize($newEvents));

        $oldBreaks = dtrLoadBreakConfig($conn);
        $newBreaks = BreakConfig::load();
        $this->same('break config', $oldBreaks, $newBreaks);

        $lunch = $conn->query('SELECT lunch_out, lunch_in FROM system_settings WHERE id = 1')->fetch_assoc();

        $before = $this->checks;

        foreach (DB::table('teachers')->orderBy('id')->get() as $t) {

            $old = dtrPreloadTeacherData($conn, (int) $t->id, $from, $to);
            $new = AttendanceData::preload((int) $t->id, $from, $to);
            $this->same("preload #{$t->id}", $this->normalize($old), $this->normalize($new));

            $remarksOld = [];
            $res = $conn->query("SELECT date, remark_type, duration, half_day_session, included_in_total_hours FROM dtr_remarks WHERE teacher_id = {$t->id}");
            while ($r = $res->fetch_assoc()) {
                $remarksOld[$r['date']] = $r;
            }
            $remarksNew = AttendanceData::remarksByDate((int) $t->id, $from, $to);

            [$lv, $adj, $att, $abs, $sch] = $old;
            [$lvN, $adjN, $attN, $absN, $schN] = $new;

            for ($d = strtotime($from); $d <= strtotime($to); $d = strtotime('+1 day', $d)) {
                $date = date('Y-m-d', $d);

                $this->same("status #{$t->id} {$date}",
                    getTeacherDayStatus($date, $lv, $adj, $oldEvents, $att[$date] ?? null, $abs, $sch, teacherId: (int) $t->id),
                    Attendance\getTeacherDayStatus($date, $lvN, $adjN, $newEvents, $attN[$date] ?? null, $absN, $schN, teacherId: (int) $t->id));

                $this->same("hours #{$t->id} {$date}",
                    round(getTeacherDayHours($date, $lv, $adj, $oldEvents, $remarksOld[$date] ?? null, $att[$date] ?? null, $abs, $sch, $lunch['lunch_out'], $lunch['lunch_in'], teacherId: (int) $t->id, breakConfig: $oldBreaks), 6),
                    round(Attendance\getTeacherDayHours($date, $lvN, $adjN, $newEvents, $remarksNew[$date] ?? null, $attN[$date] ?? null, $absN, $schN, $lunch['lunch_out'], $lunch['lunch_in'], teacherId: (int) $t->id, breakConfig: $newBreaks), 6));
            }
        }

        $this->line('  day status + hours: '.($this->checks - $before).' checks');
    }

    /** Kiosk scan routing for every person and date at times across the day. */
    private function compareScanRouting(mysqli $conn, string $from, string $to): void
    {
        $before = $this->checks;
        $breaks = BreakConfig::load();
        $times = ['05:30:00', '06:50:00', '07:00:00', '07:05:00', '08:30:00', '11:55:00', '12:10:00', '12:20:00',
            '12:58:00', '13:05:00', '14:25:00', '15:00:00', '15:45:00', '21:59:00', '22:10:00', '23:50:00'];

        foreach (DB::table('teachers')->orderBy('id')->get() as $t) {
            $sched = AttendanceData::schedules((int) $t->id);

            foreach (DB::table('attendance')->where('teacher_id', $t->id)->get() as $row) {
                $date = $row->date;
                $prev = date('Y-m-d', strtotime($date.' -1 day'));
                $today = $sched[strtolower(date('l', strtotime($date)))] ?? null;
                $yesterday = $sched[strtolower(date('l', strtotime($prev)))] ?? null;
                $prevRow = DB::table('attendance')->where('teacher_id', $t->id)->where('date', $prev)->first();

                foreach ($times as $time) {
                    $ctx = [
                        'date' => $date, 'time' => $time,
                        'schedule' => $today, 'prevSchedule' => $yesterday,
                        'row' => (array) $row, 'prevRow' => $prevRow ? (array) $prevRow : null,
                        'break' => Attendance\dtrBreakForSchedule($breaks, $today),
                    ];
                    $this->same("route #{$t->id} {$date} {$time}", dtrRouteScan($ctx), Attendance\dtrRouteScan($ctx));
                }
            }
        }

        $this->line('  scan routing: '.($this->checks - $before).' checks');
    }

    private function comparePendingReview(mysqli $conn): void
    {
        $before = $this->checks;

        $old = dtrComputePendingReview($conn);
        $new = PendingReview::compute();
        $this->same('pending review items', $this->normalize($old), $this->normalize($new));
        $this->same('pending review groups', $this->normalize(dtrGroupPendingByPeriod($old)), $this->normalize(PendingReview::groupByPeriod($new)));
        $this->same('review summary', dtrReviewSummary($conn), PendingReview::summary());

        $this->line('  pending review: '.count($new).' items, '.($this->checks - $before).' checks');
    }

    private function compareMonthlySummaries(mysqli $conn, string $from, string $to): void
    {
        $before = $this->checks;

        for ($m = strtotime(date('Y-m-01', strtotime($from))); $m <= strtotime($to); $m = strtotime('+1 month', $m)) {
            $start = date('Y-m-01', $m);
            $end = date('Y-m-t', $m);
            $this->same("monthly summary {$start}", $this->normalize(dtrBuildMonthlySummary($conn, $start, $end)), $this->normalize(MonthlySummary::build($start, $end)));
        }

        $this->line('  monthly summaries: '.($this->checks - $before).' checks');
    }

    /**
     * mysqli returns some numbers as strings (query()) and others as ints
     * (prepared statements); PDO returns ints. Compare values, not types.
     * Keyed lookups (e.g. schedules by weekday) are compared regardless of
     * key order; lists keep their order.
     */
    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $value = array_map(fn ($v) => $this->normalize($v), $value);

            if (! array_is_list($value)) {
                ksort($value);
            }

            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $value;
    }
}
