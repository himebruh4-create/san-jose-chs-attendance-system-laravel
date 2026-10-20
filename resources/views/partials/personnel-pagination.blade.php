{{-- Pagination of the personnel card lists; keeps the search and filters. --}}
@if ($totalPages > 1)
    @php
        $query = array_filter(['search' => $search, 'academic_status' => $filterAcademic, 'employment_type' => $filterEmployment], fn ($v) => $v !== '');
        $pageUrl = fn ($p) => route($route, $query + ['page' => $p]);
    @endphp
    <nav class="pagination" aria-label="Personnel pages">
        @if ($page > 1)
            <a href="{{ $pageUrl(1) }}">&laquo; First</a>
            <a href="{{ $pageUrl($page - 1) }}">&lsaquo; Previous</a>
        @endif

        @for ($i = 1; $i <= $totalPages; $i++)
            @if ($i == $page)
                <span class="active">{{ $i }}</span>
            @else
                <a href="{{ $pageUrl($i) }}">{{ $i }}</a>
            @endif
        @endfor

        @if ($page < $totalPages)
            <a href="{{ $pageUrl($page + 1) }}">Next &rsaquo;</a>
            <a href="{{ $pageUrl($totalPages) }}">Last &raquo;</a>
        @endif
    </nav>
@endif
