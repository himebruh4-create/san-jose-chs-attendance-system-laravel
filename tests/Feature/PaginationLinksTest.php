<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every paginated list shows page numbers only: no Previous / Next, "…"
 * for gaps, the current page marked, and First / Last on a long list only.
 */
class PaginationLinksTest extends TestCase
{
    /** $count active personnel, named so they sort in order (no schedules: not needed here). */
    private function personnel(int $count, int $isDeleted = 0): void
    {
        DB::table('teachers')->insert(array_map(fn (int $n) => [
            'id_number' => sprintf('P-%04d', $n), 'fullname' => sprintf('Person %03d', $n), 'department' => 'Teacher I',
            'academic_status' => 'Academic', 'employment_type' => 'Regular', 'is_deleted' => $isDeleted,
            'deleted_at' => $isDeleted ? '2026-10-01 08:00:00' : null,
        ], range(1, $count)));
    }

    /** The visible labels of the page bar, in order. */
    private function pageLabels(string $html): array
    {
        preg_match('#<nav class="pagination"[^>]*>(.*?)</nav>#s', $html, $nav);
        preg_match_all('#<(?:a|span)[^>]*>([^<]*)</(?:a|span)>#', $nav[1] ?? '', $labels);

        return array_map(fn (string $label) => html_entity_decode(trim($label)), $labels[1]);
    }

    public function test_a_long_list_shows_a_window_of_pages_with_gaps_and_first_and_last(): void
    {
        $this->personnel(66);   // 14 pages of 5 on the Attendance Report

        $response = $this->actingAs($this->account('admin'))->get(route('admin.attendance-report', ['page' => 5]));

        $this->assertSame(['First', '1', '…', '4', '5', '6', '…', '14', 'Last'], $this->pageLabels($response->getContent()));
        $response->assertSee('<span class="active" aria-current="page">5</span>', false)
            ->assertDontSee('Previous')
            ->assertDontSee('Next &rsaquo;', false);
    }

    public function test_a_short_list_shows_its_page_numbers_without_first_last_previous_or_next(): void
    {
        $this->personnel(13);   // 2 pages of 12 on the personnel lists

        foreach (['superadmin', 'admin', 'principal'] as $role) {
            $this->flushSession();
            $html = $this->actingAs($this->account($role))->get(route("{$role}.personnel"))->getContent();

            $this->assertSame(['1', '2'], $this->pageLabels($html), $role);
        }
    }

    public function test_a_single_page_shows_no_page_bar(): void
    {
        $this->personnel(3);

        $this->actingAs($this->account('admin'))->get(route('admin.personnel'))
            ->assertDontSee('class="pagination"', false);
    }

    public function test_scan_photos_have_page_numbers_instead_of_newer_and_older(): void
    {
        DB::table('scan_photos')->insert(array_map(fn (int $n) => [
            'attendance_date' => date('Y-m-d'), 'scanned_at' => date('Y-m-d').' 07:'.sprintf('%02d', $n).':00', 'outcome' => 'not_found', 'barcode' => '9'.$n,
        ], range(0, 48)));   // 49 photos, 2 pages of 48

        $html = $this->actingAs($this->account('superadmin'))->get(route('superadmin.scan-photos'))->getContent();

        $this->assertSame(['1', '2'], $this->pageLabels($html));
        $this->assertStringNotContainsString('Newer', $html);
        $this->assertStringNotContainsString('Older', $html);
    }

    public function test_the_audit_log_and_recycle_bin_load_one_page_at_a_time(): void
    {
        $this->actingAs($this->account('superadmin'));
        DB::table('audit_logs')->insert(array_map(fn (int $n) => ['actor' => 'system', 'action' => "test.entry{$n}"], range(1, 30)));
        $this->personnel(21, isDeleted: 1);
        $auditTotal = DB::table('audit_logs')->count();

        $auditPage2 = $this->getJson('/data/audit-log?page=2');
        $binPage2 = $this->getJson('/data/recycle-bin?type=teacher&page=2');

        $auditPage2->assertJson(['total' => $auditTotal, 'page' => 2, 'last_page' => (int) ceil($auditTotal / 25)])
            ->assertJsonCount($auditTotal - 25, 'entries');
        $binPage2->assertJson(['total' => 21, 'page' => 2, 'last_page' => 2])->assertJsonCount(1, 'rows');
    }
}
