{{-- The personnel card grid with its pagination (all three roles). --}}
@if (empty($teachers))
    <div class="pc-empty">
        <i class="fa-solid fa-user-slash"></i>
        <strong>No personnel found</strong>
        <p>Try a different search or filter.</p>
    </div>
@else
    <div class="personnel-grid">
        @foreach ($teachers as $teacher)
            @include('partials.personnel-card', ['number' => ($page - 1) * $limit + $loop->iteration])
        @endforeach
    </div>
@endif

@include('partials.personnel-pagination')
