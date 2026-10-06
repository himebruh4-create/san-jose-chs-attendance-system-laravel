<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function App\Domain\Attendance\dtrDayCalc;
use function App\Domain\Attendance\dtrReviewPeriodStart;
use function App\Domain\Attendance\dtrScheduleShape;

/**
 * The pure attendance rules (app/Domain/Attendance/rules.php). No database.
 * `php artisan legacy:parity` checks the same functions against the
 * native-PHP originals on real data.
 */
class AttendanceRulesTest extends TestCase
{
    public function test_schedule_shapes(): void
    {
        $this->assertSame('day', dtrScheduleShape(['time_in' => '07:00:00', 'time_out' => '15:00:00']));
        $this->assertSame('afternoon', dtrScheduleShape(['time_in' => '14:00:00', 'time_out' => '22:00:00']));
        $this->assertSame('overnight', dtrScheduleShape(['time_in' => '22:00:00', 'time_out' => '06:00:00']));
    }

    public function test_lateness_has_no_grace_period(): void
    {
        $schedule = ['time_in' => '07:00:00', 'time_out' => '15:00:00'];

        $this->assertFalse(dtrDayCalc($schedule, ['am_arrival' => '07:00:00', 'pm_departure' => '15:00:00'], null)['late']);
        $this->assertTrue(dtrDayCalc($schedule, ['am_arrival' => '07:00:01', 'pm_departure' => '15:00:00'], null)['late']);
    }

    public function test_hours_are_capped_at_the_scheduled_end_until_overtime_is_approved(): void
    {
        $schedule = ['time_in' => '07:00:00', 'time_out' => '15:00:00'];

        $calc = dtrDayCalc($schedule, ['am_arrival' => '07:00:00', 'pm_departure' => '17:00:00'], null);
        $this->assertTrue($calc['overtime']);
        $this->assertFalse($calc['overtime_approved']);
        $this->assertEquals(8, $calc['hours']);

        // An adjustment that supplies the OUT is the approval.
        $approved = dtrDayCalc($schedule, ['am_arrival' => '07:00:00', 'pm_departure' => '17:00:00'], ['pm_departure' => '17:00:00']);
        $this->assertTrue($approved['overtime_approved']);
        $this->assertEquals(10, $approved['hours']);
    }

    public function test_early_departure_and_missing_out(): void
    {
        $schedule = ['time_in' => '07:00:00', 'time_out' => '15:00:00'];

        $this->assertTrue(dtrDayCalc($schedule, ['am_arrival' => '07:00:00', 'pm_departure' => '14:30:00'], null)['early_departure']);
        $this->assertFalse(dtrDayCalc($schedule, ['am_arrival' => '07:00:00', 'pm_departure' => '14:31:00'], null)['early_departure']);
        $this->assertTrue(dtrDayCalc($schedule, ['am_arrival' => '07:00:00'], null)['missing_out']);
    }

    public function test_overnight_shift_hours_cross_midnight(): void
    {
        $calc = dtrDayCalc(['time_in' => '22:00:00', 'time_out' => '06:00:00'], ['pm_arrival' => '22:00:00', 'pm_departure' => '06:00:00'], null);

        $this->assertTrue($calc['complete']);
        $this->assertEquals(8, $calc['hours']);
    }

    public function test_review_periods_start_on_the_first_sunday_of_the_month(): void
    {
        $this->assertSame('2026-10-04', dtrReviewPeriodStart('2026-10-06'));
        // Before October's first Sunday the day still belongs to September's period.
        $this->assertSame('2026-09-06', dtrReviewPeriodStart('2026-10-03'));
    }
}
