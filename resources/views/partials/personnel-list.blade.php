{{-- The personnel card grid with its pagination (all three roles). The live
     filter replaces the contents of #personnelResults. The grid scrolls up
     and down inside the card (the Attendance Adjustments list's style) with
     its header bar in view. --}}
<div id="personnelResults">
    @if (empty($teachers))
        <div class="pc-empty">
            <i class="fa-solid fa-user-slash"></i>
            <strong>No personnel found</strong>
            <p>Try a different search or filter.</p>
        </div>
    @else
        <div class="scroll-panel personnel-scroll" tabindex="0" aria-label="Personnel list">
            <div class="scroll-panel-head personnel-scroll-head">
                Personnel {{ ($page - 1) * $limit + 1 }}&ndash;{{ ($page - 1) * $limit + count($teachers) }}
                @if ($totalPages > 1)
                    &middot; Page {{ $page }} of {{ $totalPages }}
                @endif
            </div>
            <div class="personnel-grid">
                @foreach ($teachers as $teacher)
                    @include('partials.personnel-card', ['number' => ($page - 1) * $limit + $loop->iteration])
                @endforeach
            </div>
        </div>
    @endif

    @include('partials.personnel-pagination')
</div>
