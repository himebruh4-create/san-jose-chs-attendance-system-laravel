<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The Daily Report and the Personnel lists scroll inside their card with
 * their header in view (.scroll-panel, the Attendance Adjustments style);
 * printouts never scroll.
 */
class ScrollingListsTest extends TestCase
{
    public function test_the_daily_report_list_scrolls_with_a_sticky_header(): void
    {
        $this->teacher(['created_at' => '2026-01-01 00:00:00']);

        $this->actingAs($this->account('admin'))->get(route('admin.reports', ['tab' => 'daily']))
            ->assertSee('<div class="table-scroll scroll-panel sticky-table-wrapper" tabindex="0" aria-label="Attendance list">', false);
    }

    public function test_the_daily_printout_does_not_scroll(): void
    {
        $this->teacher(['created_at' => '2026-01-01 00:00:00']);

        $this->actingAs($this->account('admin'))->get(route('reports.attendance-print', ['period' => 'daily', 'date' => date('Y-m-d')]))
            ->assertOk()
            ->assertDontSee('scroll-panel', false);
    }

    public function test_every_personnel_list_scrolls_with_its_header_bar_in_view(): void
    {
        $this->teacher();

        foreach (['superadmin', 'admin', 'principal'] as $role) {
            $this->flushSession();
            $this->actingAs($this->account($role))->get(route("{$role}.personnel"))
                ->assertSee('<div class="scroll-panel personnel-scroll" tabindex="0" aria-label="Personnel list">', false)
                ->assertSee('class="scroll-panel-head personnel-scroll-head"', false);
        }
    }

    public function test_the_attendance_adjustments_list_uses_the_same_scroll_panel(): void
    {
        $this->actingAs($this->account('superadmin'))->get(route('superadmin.adjustments'))
            ->assertSee('class="pending-scroll-wrapper sticky-table-wrapper scroll-panel adjustments-scroll"', false);
    }
}
