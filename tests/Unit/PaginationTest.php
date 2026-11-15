<?php

namespace Tests\Unit;

use App\Support\Pagination;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * The page numbers every paginated list shows (App\Support\Pagination; the
 * same rule as paginationWindow() in public/js/app-shell.js). null is "…".
 */
class PaginationTest extends TestCase
{
    #[TestWith([1, 1, [1]])]
    #[TestWith([3, 7, [1, 2, 3, 4, 5, 6, 7]])]
    public function test_up_to_seven_pages_shows_every_page(int $page, int $total, array $expected): void
    {
        $this->assertSame($expected, Pagination::window($page, $total));
    }

    #[TestWith([5, 14, [1, null, 4, 5, 6, null, 14]])]
    #[TestWith([1, 14, [1, 2, 3, 4, 5, null, 14]])]
    #[TestWith([4, 14, [1, 2, 3, 4, 5, null, 14]])]
    #[TestWith([11, 14, [1, null, 10, 11, 12, 13, 14]])]
    #[TestWith([14, 14, [1, null, 10, 11, 12, 13, 14]])]
    #[TestWith([5, 8, [1, null, 4, 5, 6, 7, 8]])]
    public function test_a_long_list_shows_the_ends_the_pages_around_the_current_one_and_gaps(int $page, int $total, array $expected): void
    {
        $this->assertSame($expected, Pagination::window($page, $total));
    }

    public function test_a_page_outside_the_list_is_treated_as_its_nearest_end(): void
    {
        $this->assertSame(Pagination::window(14, 14), Pagination::window(99, 14));
        $this->assertSame(['page' => 3, 'last_page' => 3, 'offset' => 50], Pagination::page(99, 51, 25));
        $this->assertSame(['page' => 1, 'last_page' => 1, 'offset' => 0], Pagination::page(0, 0, 25));
    }

    public function test_first_and_last_are_offered_only_on_a_long_list(): void
    {
        $this->assertFalse(Pagination::showsFirstAndLast(9));
        $this->assertTrue(Pagination::showsFirstAndLast(10));
    }
}
