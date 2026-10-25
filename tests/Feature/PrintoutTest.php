<?php

namespace Tests\Feature;

use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Printouts: "Printed on ... by [position]" (never the email), the
 * "[Report] - [Person] - [Range] - [Position]" title browsers suggest as the
 * saved PDF's file name, and the Audit Log entry of each print.
 */
class PrintoutTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function positions(): array
    {
        return [
            'Super Admin' => ['superadmin', 'Administrative Aide III'],
            'Admin' => ['admin', 'Administrative Officer'],
            'Principal' => ['principal', 'Principal'],
        ];
    }

    private function ana(array $attributes = []): Teacher
    {
        return $this->teacher($attributes + ['fullname' => 'Ana Cruz', 'created_at' => '2026-01-01 00:00:00']);
    }

    #[DataProvider('positions')]
    public function test_printouts_the_server_builds_show_the_position_and_name_the_file_but_never_show_the_email(string $role, string $position): void
    {
        $this->ana();
        $this->actingAs($this->account($role, ['email' => "printer-{$role}@test.local"]));

        $printouts = [
            "Daily Report - 2026-10-05 - {$position}" => $this->get(route('reports.attendance-print', ['period' => 'daily', 'date' => '2026-10-05'])),
            "Monthly Summary Report - 2026-10 - {$position}" => $this->get(route('reports.monthly-summary', ['month' => 10, 'year' => 2026])),
            "Absence Report - 2026-10-05 to 2026-10-10 - {$position}" => $this->get(route('reports.absences', ['period' => 'weekly', 'from' => '2026-10-05', 'to' => '2026-10-10'])),
        ];

        foreach ($printouts as $title => $printout) {
            $printout->assertSee("<title>{$title}</title>", false)
                ->assertDontSee("printer-{$role}@test.local");
            $this->assertMatchesRegularExpression(
                '/Printed on [A-Z][a-z]+day, [A-Z][a-z]+ \d{1,2}, \d{4} at \d{1,2}:\d{2} [AP]M by '.$position.'</',
                $printout->getContent()
            );
        }
    }

    public function test_one_person_printouts_add_the_persons_name_after_the_report_name(): void
    {
        $ana = $this->ana();
        $this->actingAs($this->account('principal'));

        $printouts = [
            'Daily Report - Ana Cruz - 2026-10-22 - Principal' => route('reports.attendance-print', ['period' => 'daily', 'date' => '2026-10-22', 'teacher_id' => $ana->id]),
            'Weekly Roster - Ana Cruz - 2026-10-19 to 2026-10-24 - Principal' => route('reports.attendance-print', ['period' => 'weekly', 'date' => '2026-10-19', 'teacher_id' => $ana->id]),
            'Monthly Summary Report - Ana Cruz - 2026-10 - Principal' => route('reports.attendance-print', ['period' => 'monthly', 'date' => '2026-10-01', 'teacher_id' => $ana->id]),
            'Absence Report - Ana Cruz - 2026-10 - Principal' => route('reports.absences', ['period' => 'monthly', 'from' => '2026-10-01', 'to' => '2026-10-31', 'teacher_id' => $ana->id]),
        ];

        foreach ($printouts as $title => $url) {
            $this->get($url)->assertSee("<title>{$title}</title>", false);
        }
    }

    public function test_characters_windows_does_not_allow_in_file_names_are_removed_from_the_title(): void
    {
        $person = $this->ana(['fullname' => 'Ana "Jo" Cruz/Reyes: *?<>|\\']);

        $response = $this->actingAs($this->account('admin'))
            ->get(route('reports.attendance-print', ['period' => 'daily', 'date' => '2026-10-05', 'teacher_id' => $person->id]));

        $response->assertSee('<title>Daily Report - Ana Jo CruzReyes - 2026-10-05 - Administrative Officer</title>', false);
    }

    #[DataProvider('positions')]
    public function test_the_reports_page_gives_the_weekly_roster_print_its_title_and_the_position(string $role, string $position): void
    {
        $response = $this->actingAs($this->account($role, ['email' => "printer-{$role}@test.local"]))
            ->get(route("{$role}.reports", ['tab' => 'weekly', 'week' => '2026-W43']));

        $response->assertViewHas('weeklyRosterPrint', fn (array $print) => $print['title'] === "Weekly Roster - 2026-10-19 to 2026-10-24 - {$position}")
            ->assertSee('"printedBy":"'.$position.'"', false)
            ->assertDontSee("printer-{$role}@test.local");
    }

    public function test_the_attendance_monitoring_print_is_named_by_person_range_and_position(): void
    {
        $ana = $this->ana();

        $response = $this->actingAs($this->account('principal', ['email' => 'head@test.local']))
            ->get(route('principal.monitoring', ['teacher_id' => $ana->id, 'date_from' => '2026-09-09', 'date_to' => '2026-10-22']));

        $response->assertSee('"title":"Attendance Monitoring - Ana Cruz - 2026-09-09 to 2026-10-22 - Principal"', false)
            ->assertSee('"printedBy":"Principal"', false)
            ->assertDontSee('head@test.local');
    }

    #[TestWith(['superadmin', 'Administrative Aide III'])]
    #[TestWith(['admin', 'Administrative Officer'])]
    public function test_each_dtr_and_all_dtr_are_named_by_person_month_and_position(string $role, string $position): void
    {
        $this->ana();

        $response = $this->actingAs($this->account($role))->get(route("{$role}.attendance-report", ['monthPicker' => '2026-10']));

        $response->assertViewHas('teacherReports', fn (array $reports) => $reports[0]['print']['title'] === "DTR - Ana Cruz - 2026-10 - {$position}")
            ->assertViewHas('allDtrPrint', fn (array $print) => $print['title'] === "DTR - All Personnel - 2026-10 - {$position}");
    }

    public function test_id_badges_are_named_by_person_and_position(): void
    {
        $ana = $this->ana();

        $response = $this->actingAs($this->account('superadmin'))->get(route('superadmin.personnel'));

        $response->assertViewHas('badgePrints', fn (array $prints) => $prints[$ana->id_number]['title'] === 'ID Badge - Ana Cruz - Administrative Aide III')
            ->assertViewHas('allBadgesPrint', fn (array $print) => $print['title'] === 'ID Badges - All Personnel - Administrative Aide III');
    }

    public function test_the_recovery_codes_print_is_named_by_date_and_position_and_says_to_keep_it_on_paper(): void
    {
        $response = $this->actingAs($this->account('superadmin'))
            ->post(route('superadmin.settings.recovery-codes'), ['current_password' => 'Secret#2026']);

        $response->assertSee('"title":"Recovery Codes - '.date('Y-m-d').' - Administrative Aide III"', false)
            ->assertSee("Print on paper and keep in a sealed envelope. Don't save as PDF.", false)
            ->assertSee('<title>Recovery Codes</title>', false);
    }

    public function test_the_admin_position_is_administrative_officer_on_the_sidebar_and_dashboard(): void
    {
        $response = $this->actingAs($this->account('admin'))->get(route('admin.dashboard'));

        $response->assertSee('<span class="sidebar-role-badge">Administrative Officer</span>', false)
            ->assertSee('<h1>Administrative Officer Dashboard</h1>', false)
            ->assertDontSee('Officer IV');
    }

    public function test_a_printout_the_server_builds_is_recorded_in_the_audit_log_with_the_email_report_and_range(): void
    {
        $ana = $this->ana();
        $admin = $this->account('admin');

        $this->actingAs($admin)->get(route('reports.attendance-print', ['period' => 'weekly', 'date' => '2026-10-07', 'teacher_id' => $ana->id]));

        $entry = DB::table('audit_logs')->where('action', 'report.printed')->sole();
        $this->assertSame([$admin->email, 'teacher', $ana->id], [$entry->actor, $entry->subject_type, $entry->subject_id]);
        $this->assertSame(['report' => 'Weekly Roster', 'range' => '2026-10-05 to 2026-10-10', 'personnel' => 'Ana Cruz'], json_decode($entry->details, true));
    }

    public function test_a_printout_the_browser_builds_is_recorded_through_the_print_log(): void
    {
        $ana = $this->ana();
        $principal = $this->account('principal');

        $response = $this->actingAs($principal)->postJson(route('reports.print-log'), [
            'report' => 'Attendance Monitoring', 'range' => '2026-09-09 to 2026-10-22', 'teacher_id' => $ana->id,
        ]);

        $response->assertExactJson(['success' => true]);
        $entry = DB::table('audit_logs')->where('action', 'report.printed')->sole();
        $this->assertSame([$principal->email, $ana->id], [$entry->actor, $entry->subject_id]);
        $this->assertSame(['report' => 'Attendance Monitoring', 'range' => '2026-09-09 to 2026-10-22', 'personnel' => 'Ana Cruz'], json_decode($entry->details, true));
    }

    #[TestWith(['admin', ['report' => 'ID Badge'], 'report'])]
    #[TestWith(['principal', ['report' => 'DTR', 'range' => '2026-10'], 'report'])]
    #[TestWith(['admin', ['report' => 'DTR', 'range' => 'October'], 'range'])]
    public function test_the_print_log_refuses_a_report_the_role_cannot_print_or_a_malformed_range(string $role, array $payload, string $invalidField): void
    {
        $response = $this->actingAs($this->account($role))->postJson(route('reports.print-log'), $payload);

        $response->assertJsonValidationErrors($invalidField);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'report.printed']);
    }

    public function test_a_guest_cannot_write_to_the_print_log(): void
    {
        $response = $this->post(route('reports.print-log'), ['report' => 'Daily Report']);

        $response->assertRedirect(route('login'));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'report.printed']);
    }
}
