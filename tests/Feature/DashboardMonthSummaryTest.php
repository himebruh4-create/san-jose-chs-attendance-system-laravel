<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * The dashboards' "This month" row: this month's totals up to today, the
 * same numbers as Reports -> Monthly for the current month.
 */
class DashboardMonthSummaryTest extends TestCase
{
    /**
     * The app reads the real clock, so the records sit on this month's first
     * two working days (Sundays are never counted). Those that are still in
     * the future are simply not counted by either page.
     *
     * @return array{0: string, 1: string}
     */
    private function firstTwoWorkingDays(): array
    {
        $days = [];
        for ($day = strtotime(date('Y-m-01')); count($days) < 2; $day = strtotime('+1 day', $day)) {
            if (date('N', $day) != 7) {
                $days[] = date('Y-m-d', $day);
            }
        }

        return $days;
    }

    /**
     * Two people with every day scheduled since before this month: Ana
     * scans and Ben is absent on the first working day, Ana is on leave on
     * the second; the other days are Pending Review or Not Yet Recorded.
     */
    private function thisMonthsRecords(): void
    {
        $everyDay = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $ana = $this->teacher(['fullname' => 'Ana Cruz', 'created_at' => '2026-01-01 00:00:00'], days: $everyDay);
        $ben = $this->teacher(['fullname' => 'Ben Reyes', 'created_at' => '2026-01-01 00:00:00'], days: $everyDay);
        [$first, $second] = $this->firstTwoWorkingDays();

        DB::table('attendance')->insert(['teacher_id' => $ana->id, 'date' => $first, 'am_arrival' => '06:50:00']);
        DB::table('confirmed_absences')->insert(['teacher_id' => $ben->id, 'absence_date' => $first]);
        DB::table('teacher_leaves')->insert(['teacher_id' => $ana->id, 'leave_type' => 'Sick Leave', 'leave_from' => $second, 'leave_until' => $second]);
    }

    #[TestWith(['superadmin'])]
    #[TestWith(['admin'])]
    #[TestWith(['principal'])]
    public function test_the_dashboard_shows_the_monthly_reports_totals_for_this_month(string $role): void
    {
        $this->thisMonthsRecords();
        $this->actingAs($this->account($role));

        $dashboard = $this->get(route("{$role}.dashboard"));
        $monthlyReport = $this->get(route("{$role}.reports", ['tab' => 'monthly', 'monthPicker' => date('Y-m')]));

        $reportTotals = $monthlyReport->viewData('monthly')['totals'];
        $dashboard->assertViewHas('monthTotals', fn (array $totals) => $totals === [
            'Present' => $reportTotals['Present'],
            'Late' => $reportTotals['Late'],
            'Absent' => $reportTotals['Absent'],
            'On Leave' => $reportTotals['On Leave'],
            'Pending Review' => $reportTotals['Pending Review'],
        ])->assertSeeInOrder(['This month:', 'Present', 'Late', 'Absent', 'On Leave', 'Pending Review', 'View calendar']);
        [$first, $second] = $this->firstTwoWorkingDays();
        $this->assertSame([(int) ($first <= date('Y-m-d')), (int) ($second <= date('Y-m-d'))], [$reportTotals['Absent'], $reportTotals['On Leave']]);
    }

    /**
     * This month's changes: a holiday from a Saturday to the Monday after
     * (its Sunday is a calendar day only when someone works Sundays), a second holiday on that Monday,
     * an asynchronous day on that Monday too, and an inactive change.
     *
     * @return array{saturday: string, monday: string}
     */
    private function thisMonthsChanges(): array
    {
        for ($day = strtotime(date('Y-m-01')); date('N', $day) != 6 || date('m', strtotime('+2 days', $day)) !== date('m'); $day = strtotime('+1 day', $day)) {
        }
        $saturday = date('Y-m-d', $day);
        $monday = date('Y-m-d', strtotime('+2 days', $day));

        DB::table('school_events')->insert([
            ['event_name' => 'Long Weekend', 'event_type' => 'Holiday', 'date_from' => $saturday, 'date_to' => $monday, 'status' => 'Active'],
            ['event_name' => 'Town Fiesta', 'event_type' => 'Holiday', 'date_from' => $monday, 'date_to' => $monday, 'status' => 'Active'],
            ['event_name' => 'Online Classes', 'event_type' => 'Asynchronous', 'date_from' => $monday, 'date_to' => $monday, 'status' => 'Active'],
            ['event_name' => 'Cancelled Meet', 'event_type' => 'Other: Meet', 'date_from' => $saturday, 'date_to' => $saturday, 'status' => 'Inactive'],
        ]);

        return ['saturday' => $saturday, 'monday' => $monday];
    }

    #[TestWith(['superadmin'])]
    #[TestWith(['admin'])]
    #[TestWith(['principal'])]
    public function test_this_month_counts_the_days_with_holidays_and_with_schedule_changes_once_each(string $role): void
    {
        $this->thisMonthsRecords();
        $this->thisMonthsChanges();
        $this->actingAs($this->account($role));

        $dashboard = $this->get(route("{$role}.dashboard"));
        $reportTotals = $this->get(route("{$role}.reports", ['tab' => 'monthly', 'monthPicker' => date('Y-m')]))->viewData('monthly')['totals'];

        // Ana and Ben work every day, Sundays too, so the holiday covers 3 calendar days;
        // two people and two holidays on the Monday still count each day once.
        $dashboard->assertViewHas('monthChangeDays', ['Holidays' => 3, 'Schedule Changes' => 1])
            ->assertSeeInOrder(['Pending Review', 'Holidays', 'Schedule Changes', 'View calendar'])
            ->assertViewHas('monthTotals', fn (array $totals) => array_intersect_key($totals, array_flip(['Present', 'Late', 'Absent', 'On Leave']))
                === array_intersect_key($reportTotals, array_flip(['Present', 'Late', 'Absent', 'On Leave'])));
    }

    #[TestWith(['holidays', 2])]
    #[TestWith(['changes', 1])]
    public function test_holidays_and_schedule_changes_open_the_calendar_with_their_days_highlighted(string $highlight, int $highlightedDays): void
    {
        $days = $this->thisMonthsChanges();
        $this->actingAs($this->account('admin'));
        $calendarUrl = route('admin.reports', ['tab' => 'monthly', 'monthPicker' => date('Y-m'), 'highlight' => $highlight]);

        $this->get(route('admin.dashboard'))->assertSee('href="'.e($calendarUrl).'"', false);

        $html = $this->get($calendarUrl)->getContent();
        $this->assertSame($highlightedDays, substr_count($html, ' is-highlighted"'));
        $this->assertMatchesRegularExpression('#class="cal-cell[^"]* is-highlighted" data-date="'.$days['monday'].'"#', $html);
    }

    #[TestWith(['superadmin', true])]
    #[TestWith(['admin', false])]
    #[TestWith(['principal', false])]
    public function test_only_the_super_admins_pending_review_number_opens_pending_review(string $role, bool $linksToPendingReview): void
    {
        $this->thisMonthsRecords();
        $pendingReviewLink = route('superadmin.adjustments', ['tab' => 'pending']);

        $response = $this->actingAs($this->account($role))->get(route("{$role}.dashboard"));

        $response->assertSee('href="'.e(route("{$role}.reports", ['tab' => 'monthly', 'monthPicker' => date('Y-m')])).'"', false);
        if ($linksToPendingReview) {
            $response->assertSee('href="'.e($pendingReviewLink).'"', false);
        } else {
            $response->assertDontSee($pendingReviewLink);
        }
    }
}
