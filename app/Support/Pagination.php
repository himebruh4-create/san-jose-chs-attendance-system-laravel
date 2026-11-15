<?php

namespace App\Support;

/**
 * The page links every paginated list shows (resources/views/partials/
 * pagination.blade.php, and paginationWindow() in public/js/app-shell.js,
 * which must stay the same): page numbers only, never Previous / Next. A
 * long list shows the first and last page, the current page with one on
 * each side, and "…" for the pages in between, e.g. 1 … 4 5 6 … 14.
 * "First" / "Last" are added only when the list is long.
 */
class Pagination
{
    /** Up to this many pages, every page number is shown. */
    public const SHOW_ALL_UP_TO = 7;

    /** From this many pages on, the list also gets "First" and "Last". */
    public const LONG_LIST_PAGES = 10;

    /**
     * Page numbers to show, with null for each "…" gap.
     *
     * @return list<?int>
     */
    public static function window(int $page, int $totalPages): array
    {
        if ($totalPages <= self::SHOW_ALL_UP_TO) {
            return range(1, max(1, $totalPages));
        }

        $page = min(max(1, $page), $totalPages);

        // Near either end the run of numbers is 5 long, so the bar keeps
        // the same width wherever the current page is.
        [$from, $to] = match (true) {
            $page <= 4 => [1, 5],
            $page >= $totalPages - 3 => [$totalPages - 4, $totalPages],
            default => [$page - 1, $page + 1],
        };

        $pages = [];

        if ($from > 1) {
            $pages[] = 1;
            if ($from > 2) {
                $pages[] = null;
            }
        }

        array_push($pages, ...range($from, $to));

        if ($to < $totalPages) {
            if ($to < $totalPages - 1) {
                $pages[] = null;
            }
            $pages[] = $totalPages;
        }

        return $pages;
    }

    /**
     * A requested page, kept within 1..last page, and its row offset.
     *
     * @return array{page: int, last_page: int, offset: int}
     */
    public static function page(int $requested, int $total, int $perPage): array
    {
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $requested), $lastPage);

        return ['page' => $page, 'last_page' => $lastPage, 'offset' => ($page - 1) * $perPage];
    }

    public static function showsFirstAndLast(int $totalPages): bool
    {
        return $totalPages >= self::LONG_LIST_PAGES;
    }
}
