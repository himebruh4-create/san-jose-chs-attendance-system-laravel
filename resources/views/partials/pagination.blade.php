{{-- Page links of a paginated list (App\Support\Pagination): page numbers
     only, "…" for gaps, and "First" / "Last" on a long list only.
     $page, $totalPages, $pageUrl (fn (int $page): string), optional $label. --}}
@if ($totalPages > 1)
    @php $longList = \App\Support\Pagination::showsFirstAndLast($totalPages); @endphp
    <nav class="pagination" aria-label="{{ $label ?? 'Pages' }}">
        @if ($longList && $page > 1)
            <a href="{{ $pageUrl(1) }}" class="page-edge">First</a>
        @endif

        @foreach (\App\Support\Pagination::window($page, $totalPages) as $number)
            @if ($number === null)
                <span class="page-gap" aria-hidden="true">&hellip;</span>
            @elseif ($number === $page)
                <span class="active" aria-current="page">{{ $number }}</span>
            @else
                <a href="{{ $pageUrl($number) }}" aria-label="Page {{ $number }}">{{ $number }}</a>
            @endif
        @endforeach

        @if ($longList && $page < $totalPages)
            <a href="{{ $pageUrl($totalPages) }}" class="page-edge">Last</a>
        @endif
    </nav>
@endif
