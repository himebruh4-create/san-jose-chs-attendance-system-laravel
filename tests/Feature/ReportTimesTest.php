<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The time columns of the Daily / Weekly reports and their printouts
 * (App\Domain\Attendance\ReportTimes): the kiosk scan, else the attendance
 * adjustment's time marked "adj", else "—", in the DTR's columns, so the
 * reports and the DTR show the same times. Mon 2026-10-05.
 */
class ReportTimesTest extends TestCase
{
    private const DAY = '2026-10-05';

    private ?Account $admin = null;

    private function person(string $in = '07:00:00', string $out = '15:00:00'): Teacher
    {
        return $this->teacher(['fullname' => 'Ana Cruz', 'created_at' => '2026-01-01 00:00:00'], $in, $out);
    }

    private function adjust(Teacher $teacher, array $times, string $type = 'Forgot to Scan'): void
    {
        DB::table('attendance_adjustments')->insert($times + [
            'teacher_id' => $teacher->id, 'adjustment_date' => self::DAY, 'reason' => $type, 'adjustment_type' => $type, 'remarks' => 'Kiosk offline',
        ]);
    }

    private function scan(Teacher $teacher, array $times): void
    {
        DB::table('attendance')->insert($times + ['teacher_id' => $teacher->id, 'date' => self::DAY]);
    }

    private function actingAsAdmin(): static
    {
        return $this->actingAs($this->admin ??= $this->account('admin'));
    }

    /** The Daily Report's row of the person: their four cells and status. */
    private function dailyRow(Teacher $teacher): array
    {
        $rows = $this->actingAsAdmin()->get(route('admin.reports', ['tab' => 'daily', 'date' => self::DAY]))->viewData('daily');

        return collect($rows)->firstWhere('teacher_id', $teacher->id);
    }

    /** "07:05 adj" style summary of a row's cells (HH:MM, then the source). */
    private function shown(array $row): array
    {
        return array_map(fn (?array $cell) => $cell ? substr($cell['time'], 0, 5).($cell['source'] === 'adjustment' ? ' adj' : '') : '—', $row['times']);
    }

    public function test_a_day_with_only_an_adjustment_shows_the_adjusted_times_marked_adj(): void
    {
        $ana = $this->person();
        $this->adjust($ana, ['am_arrival' => '07:05:00', 'pm_departure' => '15:15:00']);

        $row = $this->dailyRow($ana);
        $html = $this->get(route('admin.reports', ['tab' => 'daily', 'date' => self::DAY]))->getContent();

        $this->assertSame(['am_arrival' => '07:05 adj', 'am_departure' => '—', 'pm_arrival' => '—', 'pm_departure' => '15:15 adj'], $this->shown($row));
        $this->assertSame('Late', $row['status']);
        $this->assertMatchesRegularExpression('#7:05 AM\s*<small class="time-source"[^>]*>adj</small>#', $html);
        $this->assertStringContainsString('The time is from an attendance adjustment, not a kiosk scan.', $html);
    }

    public function test_a_day_with_a_scan_shows_the_scan_times_unmarked(): void
    {
        $ana = $this->person();
        $this->scan($ana, ['am_arrival' => '06:58:00', 'pm_departure' => '15:02:00']);

        $html = $this->actingAsAdmin()->get(route('admin.reports', ['tab' => 'daily', 'date' => self::DAY]))->getContent();

        $this->assertSame(['am_arrival' => '06:58', 'am_departure' => '—', 'pm_arrival' => '—', 'pm_departure' => '15:02'], $this->shown($this->dailyRow($ana)));
        $this->assertStringNotContainsString('class="time-source"', $html);
    }

    public function test_a_day_with_both_uses_the_scan_times_and_keeps_the_adjustments_status(): void
    {
        $ana = $this->person();
        $this->scan($ana, ['am_arrival' => '07:20:00']);                                   // late by the scan
        $this->adjust($ana, ['am_arrival' => '07:00:00', 'pm_departure' => '15:00:00']);   // the correction

        $row = $this->dailyRow($ana);

        // The scan wins its column; the adjustment fills the missing OUT.
        $this->assertSame(['am_arrival' => '07:20', 'am_departure' => '—', 'pm_arrival' => '—', 'pm_departure' => '15:00 adj'], $this->shown($row));
        // Status is unchanged: the adjustment's 07:00 makes the day on time.
        $this->assertSame('Present', $row['status']);
    }

    public function test_a_day_with_neither_shows_a_dash_in_every_time_column(): void
    {
        $ana = $this->person();

        $row = $this->dailyRow($ana);
        $html = $this->get(route('reports.attendance-print', ['period' => 'daily', 'date' => self::DAY]))->getContent();

        $this->assertSame(array_fill_keys(['am_arrival', 'am_departure', 'pm_arrival', 'pm_departure'], '—'), $this->shown($row));
        $this->assertSame('Pending Review', $row['status']);
        $this->assertStringNotContainsString('class="time-source"', $html);
    }

    public function test_an_overnight_shift_shows_its_in_under_pm_arrival_and_its_next_morning_out_under_am_departure(): void
    {
        $guard = $this->person('22:00:00', '06:00:00');
        // Stored as the kiosk stores an overnight shift: IN pm_arrival, OUT pm_departure (next morning).
        $this->adjust($guard, ['pm_arrival' => '21:55:00', 'pm_departure' => '06:10:00']);

        $this->assertSame(
            ['am_arrival' => '—', 'am_departure' => '06:10 adj', 'pm_arrival' => '21:55 adj', 'pm_departure' => '—'],
            $this->shown($this->dailyRow($guard))
        );
    }

    public function test_the_marker_is_on_the_daily_printout_and_on_one_persons_weekly_report(): void
    {
        $ana = $this->person();
        $this->adjust($ana, ['am_arrival' => '07:05:00', 'pm_departure' => '15:15:00']);
        $this->actingAsAdmin();

        $printout = $this->get(route('reports.attendance-print', ['period' => 'daily', 'date' => self::DAY]))->getContent();
        $weekly = $this->get(route('admin.reports', ['tab' => 'weekly', 'week' => self::DAY, 'teacher_id' => $ana->id]));

        $this->assertMatchesRegularExpression('#7:05 AM\s*<small class="time-source"[^>]*>adj</small>#', $printout);
        $this->assertStringContainsString('.time-source', $printout);   // styled in the printout itself
        $this->assertSame('07:05 adj', $this->shown(collect($weekly->viewData('personWeek'))->firstWhere('date', self::DAY))['am_arrival']);
    }

    public function test_the_daily_report_weekly_report_and_dtr_show_the_same_times(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('Node.js is needed to run the DTR (public/js/dtr-view.js).');
        }

        $cases = [
            'adjustment only' => [$this->person(), [], ['am_arrival' => '07:05:00', 'pm_departure' => '15:15:00']],
            'scan, adjustment fills the OUT' => [$this->person(), ['am_arrival' => '06:58:00'], ['pm_departure' => '15:05:00']],
            'overnight adjustment' => [$this->person('22:00:00', '06:00:00'), [], ['pm_arrival' => '21:55:00', 'pm_departure' => '06:10:00']],
        ];
        $this->actingAsAdmin();

        foreach ($cases as $case => [$teacher, $scans, $adjustment]) {
            if ($scans) {
                $this->scan($teacher, $scans);
            }
            $this->adjust($teacher, $adjustment);

            $daily = collect($this->get(route('admin.reports', ['tab' => 'daily', 'date' => self::DAY]))->viewData('daily'))->firstWhere('teacher_id', $teacher->id);
            $weekly = collect($this->get(route('admin.reports', ['tab' => 'weekly', 'week' => self::DAY, 'teacher_id' => $teacher->id]))->viewData('personWeek'))->firstWhere('date', self::DAY);
            $times = fn (array $row) => array_map(fn (?array $cell) => $cell ? substr($cell['time'], 0, 5) : null, $row['times']);

            $this->assertSame($times($daily), $times($weekly), "{$case}: daily vs weekly");

            $dtr = $this->dtrCells($node, $teacher);
            $report = $times($daily);

            if ($dtr['layout'] === 'shift') {
                // The DTR shows shift personnel one CLOCK IN | CLOCK OUT pair; the
                // report keeps the four columns, IN and OUT in the DTR's mapping.
                $shown = array_values(array_filter($report));
                $this->assertSame(['in' => $dtr['in'], 'out' => $dtr['out']], ['in' => $report['pm_arrival'] ?? $report['am_arrival'], 'out' => $report['am_departure'] ?? $report['pm_departure']], "{$case}: report vs DTR");
                $this->assertCount(2, $shown, "{$case}: only IN and OUT are shown");
            } else {
                $this->assertSame($report, array_diff_key($dtr, ['layout' => true]), "{$case}: report vs DTR");
            }
        }
    }

    /** The DTR's four time cells for the day, from the data the DTR page loads. */
    private function dtrCells(string $node, Teacher $teacher): array
    {
        $month = ['start' => '2026-10-01', 'end' => '2026-10-31'];
        $settings = $this->getJson('/data/recording-status')->json();

        $process = new Process([$node, base_path('tests/Support/dtr-day-cells.cjs')]);
        $process->setInput(json_encode([
            'teacher' => $this->getJson("/data/dtr-month?teacher_id={$teacher->id}&month=2026-10")->json('teacher'),
            'adjustments' => $this->getJson("/data/teacher-adjustments?teacher_id={$teacher->id}&start={$month['start']}&end={$month['end']}")->json('adjustments'),
            'leaves' => $this->getJson("/data/teacher-leaves?teacher_id={$teacher->id}")->json('leaves'),
            'absences' => $this->getJson("/data/confirmed-absences?teacher_id={$teacher->id}&start={$month['start']}&end={$month['end']}")->json('absences'),
            'remarks' => $this->getJson("/data/dtr-remarks?teacher_id={$teacher->id}&start={$month['start']}&end={$month['end']}")->json('remarks'),
            'schedule' => $this->getJson("/data/teacher-schedule?teacher_id={$teacher->id}")->json('schedule'),
            'events' => $this->getJson('/data/school-events')->json('events'),
            'lunch' => ['lunch_out' => $settings['lunch_out'], 'lunch_in' => $settings['lunch_in'], 'schedule_breaks' => $settings['schedule_breaks']],
            'year' => 2026, 'month' => 9, 'day' => 5,
        ]));
        $process->mustRun();

        return json_decode($process->getOutput(), true);
    }
}
