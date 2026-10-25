<?php

namespace Tests\Feature;

use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Personnel type tabs (All Personnel | Teaching Staff | Non-Teaching
 * Staff) on the Attendance Report (Super Admin, Admin) and the Principal's
 * Attendance Monitoring.
 */
class PersonnelTypeTabsTest extends TestCase
{
    private function person(string $name, string $type): Teacher
    {
        return $this->teacher(['fullname' => $name, 'academic_status' => $type, 'created_at' => '2026-01-01 00:00:00']);
    }

    /** @return list<string> */
    private function names(array $people): array
    {
        return array_column($people, 'fullname');
    }

    #[TestWith(['superadmin', '', ['Ana Cruz', 'Ben Reyes']])]
    #[TestWith(['superadmin', 'Academic', ['Ana Cruz']])]
    #[TestWith(['admin', 'Non-Academic', ['Ben Reyes']])]
    #[TestWith(['admin', 'Somebody', ['Ana Cruz', 'Ben Reyes']])]
    public function test_each_tab_lists_and_counts_only_its_personnel_type_and_all_lists_everyone(string $role, string $tab, array $expected): void
    {
        $this->person('Ana Cruz', 'Academic');
        $this->person('Ben Reyes', 'Non-Academic');

        $response = $this->actingAs($this->account($role))
            ->get(route("{$role}.attendance-report", array_filter(['academic_status' => $tab, 'monthPicker' => '2026-10'])));

        $response->assertViewHas('teacherReports', fn (array $people) => $this->names($people) === $expected)
            ->assertViewHas('totalTeachers', count($expected));
    }

    #[TestWith(['Academic', 'Teaching Staff', 'Administrative Officer'])]
    #[TestWith(['Non-Academic', 'Non-Teaching Staff', 'Administrative Officer'])]
    public function test_generate_all_dtr_covers_only_the_selected_tab_and_names_the_pdf_by_it(string $tab, string $label, string $position): void
    {
        $this->person('Ana Cruz', 'Academic');
        $this->person('Ben Reyes', 'Non-Academic');

        $response = $this->actingAs($this->account('admin'))
            ->get(route('admin.attendance-report', ['academic_status' => $tab, 'monthPicker' => '2026-10']));

        $expected = $tab === 'Academic' ? ['Ana Cruz'] : ['Ben Reyes'];
        $response->assertViewHas('allTeachersForDTR', fn (array $people) => $this->names($people) === $expected)
            ->assertViewHas('allDtrPrint', fn (array $print) => $print['title'] === "DTR - {$label} - 2026-10 - {$position}" && $print['personnel_type'] === $tab)
            ->assertSee("Generate All DTR ({$label})")
            ->assertSee('<input type="hidden" name="academic_status" value="'.$tab.'">', false);
    }

    public function test_all_personnel_keeps_the_all_dtr_title(): void
    {
        $response = $this->actingAs($this->account('superadmin'))->get(route('superadmin.attendance-report', ['monthPicker' => '2026-10']));

        $response->assertViewHas('allDtrPrint', fn (array $print) => $print['title'] === 'DTR - All Personnel - 2026-10 - Administrative Aide III')
            ->assertSee('Generate All DTR (All Personnel)');
    }

    public function test_the_search_works_inside_a_tab(): void
    {
        $this->person('Ana Cruz', 'Academic');
        $this->person('Ana Reyes', 'Non-Academic');
        $this->person('Carla Diaz', 'Academic');

        $response = $this->actingAs($this->account('admin'))
            ->get(route('admin.attendance-report', ['academic_status' => 'Academic', 'search' => 'Ana', 'monthPicker' => '2026-10']));

        $response->assertViewHas('teacherReports', fn (array $people) => $this->names($people) === ['Ana Cruz'])
            ->assertViewHas('allTeachersForDTR', fn (array $people) => $this->names($people) === ['Ana Cruz']);
    }

    public function test_pages_and_the_other_tabs_keep_the_tab_search_and_month(): void
    {
        foreach (range(1, 6) as $n) {
            $this->person("Teacher {$n}", 'Academic');
        }
        $this->person('Ben Reyes', 'Non-Academic');

        $firstPage = $this->actingAs($this->account('admin'))
            ->get(route('admin.attendance-report', ['academic_status' => 'Academic', 'search' => 'Teacher', 'monthPicker' => '2026-10']));
        $secondPage = $this->get(route('admin.attendance-report', ['academic_status' => 'Academic', 'search' => 'Teacher', 'monthPicker' => '2026-10', 'page' => 2]));

        $firstPage->assertSee(e(route('admin.attendance-report', ['search' => 'Teacher', 'monthPicker' => '2026-10', 'academic_status' => 'Academic', 'page' => 2])), false)
            ->assertSee(e(route('admin.attendance-report', ['academic_status' => 'Non-Academic', 'search' => 'Teacher', 'monthPicker' => '2026-10'])), false)
            ->assertSee('Page 1 of 2');
        $secondPage->assertViewHas('teacherReports', fn (array $people) => $this->names($people) === ['Teacher 6']);
    }

    public function test_a_dtr_batch_print_is_logged_with_the_selected_type(): void
    {
        $admin = $this->account('admin');

        $this->actingAs($admin)->postJson(route('reports.print-log'), ['report' => 'DTR', 'range' => '2026-10', 'personnel_type' => 'Academic'])
            ->assertExactJson(['success' => true]);

        $entry = DB::table('audit_logs')->where('action', 'report.printed')->sole();
        $this->assertSame($admin->email, $entry->actor);
        $this->assertSame(['report' => 'DTR', 'range' => '2026-10', 'personnel' => 'Teaching Staff'], json_decode($entry->details, true));
    }

    public function test_the_principals_monitoring_tab_shows_only_that_type_and_names_its_print(): void
    {
        $this->person('Ana Cruz', 'Academic');
        $ben = $this->person('Ben Reyes', 'Non-Academic');

        $response = $this->actingAs($this->account('principal'))->get(route('principal.monitoring', [
            'academic_status' => 'Academic', 'teacher_id' => $ben->id, 'date_from' => '2026-10-05', 'date_to' => '2026-10-06',
        ]));

        $response->assertViewHas('rows', fn (array $rows) => array_values(array_unique(array_column($rows, 'fullname'))) === ['Ana Cruz'])
            ->assertViewHas('allTeachers', fn (array $people) => $this->names($people) === ['Ana Cruz'])
            ->assertViewHas('selectedTeacherId', 0)
            ->assertSee('"title":"Attendance Monitoring - Teaching Staff - 2026-10-05 to 2026-10-06 - Principal"', false)
            ->assertSee('"typeLabel":"Teaching Staff"', false)
            ->assertSee('All Teaching Staff');
    }
}
