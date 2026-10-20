<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Personnel lists as cards: the Super Admin manages from the View More
 * modal's ⋮ menu; Admin and Principal see the same cards read-only.
 */
class PersonnelCardsTest extends TestCase
{
    public function test_the_super_admin_gets_the_actions_menu_and_the_add_personnel_form(): void
    {
        $ana = $this->teacher(['fullname' => 'Ana Reyes']);

        $response = $this->actingAs($this->account('superadmin'))->get(route('superadmin.personnel'));

        $response->assertSee('Ana Reyes')
            ->assertSee('id="pdMenuList"', false)
            ->assertSee('id="addPersonnelModal"', false)
            ->assertSee('id="regenerateForm-'.$ana->id.'"', false)
            ->assertSee('printAllTeacherBadges()', false);
    }

    public function test_a_failed_add_reopens_the_add_form_but_a_failed_edit_does_not(): void
    {
        $ana = $this->teacher();
        $ben = $this->teacher();
        $form = ['name' => 'Someone Else', 'academic_status' => 'Academic', 'department' => 'Teacher I', 'employment_type' => 'Regular', 'barcode' => $ana->barcode];
        $this->actingAs($this->account('superadmin'));

        $afterAdd = $this->followingRedirects()->post(route('superadmin.personnel.save'), $form);
        $afterEdit = $this->followingRedirects()->post(route('superadmin.personnel.save'), $form + ['id' => $ben->id]);

        $afterAdd->assertSee('data-reopen="1"', false)->assertSee('Barcode '.$ana->barcode.' is already used');
        $afterEdit->assertDontSee('data-reopen', false)->assertSee('Barcode '.$ana->barcode.' is already used');
    }

    #[TestWith(['admin'])]
    #[TestWith(['principal'])]
    public function test_admin_and_principal_get_read_only_cards(string $role): void
    {
        $this->teacher(['fullname' => 'Ana Reyes']);

        $response = $this->actingAs($this->account($role))->get(route("{$role}.personnel"));

        $response->assertSee('Ana Reyes')
            ->assertSee('View More')
            ->assertDontSee('pdMenuList', false)
            ->assertDontSee('addPersonnelModal', false)
            ->assertDontSee(route('superadmin.personnel.save'))
            ->assertDontSee(route('superadmin.personnel.regenerate-barcode'))
            ->assertDontSee(route('superadmin.personnel.archive'))
            ->assertDontSee('printAllTeacherBadges()', false);
    }

    #[TestWith(['admin'])]
    #[TestWith(['principal'])]
    public function test_admin_and_principal_can_filter_by_type_and_employment_and_search_by_id_number(string $role): void
    {
        $this->teacher(['fullname' => 'Ana Teaching Regular', 'academic_status' => 'Academic', 'employment_type' => 'Regular']);
        $this->teacher(['fullname' => 'Ben Staff Regular', 'academic_status' => 'Non-Academic', 'employment_type' => 'Regular']);
        $cy = $this->teacher(['fullname' => 'Cy Teaching Contractual', 'academic_status' => 'Academic', 'employment_type' => 'Contractual']);
        $this->actingAs($this->account($role));

        $filtered = $this->get(route("{$role}.personnel", ['academic_status' => 'Academic', 'employment_type' => 'Regular']));
        $byIdNumber = $this->get(route("{$role}.personnel", ['search' => $cy->id_number]));

        $filtered->assertViewHas('teachers', fn (array $rows) => array_column($rows, 'fullname') === ['Ana Teaching Regular']);
        $byIdNumber->assertViewHas('teachers', fn (array $rows) => array_column($rows, 'fullname') === ['Cy Teaching Contractual']);
    }

    public function test_the_lists_show_twelve_personnel_per_page(): void
    {
        foreach (range(1, 13) as $n) {
            $this->teacher(['fullname' => sprintf('Person %02d', $n)]);
        }
        $this->actingAs($this->account('admin'));

        $first = $this->get(route('admin.personnel'));
        $second = $this->get(route('admin.personnel', ['page' => 2]));

        $first->assertViewHas('teachers', fn (array $rows) => count($rows) === 12);
        $second->assertViewHas('teachers', fn (array $rows) => array_column($rows, 'fullname') === ['Person 13']);
    }
}
