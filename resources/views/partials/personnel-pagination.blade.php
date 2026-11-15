{{-- Pagination of the personnel card lists; keeps the search and filters. --}}
@php
    $query = array_filter(['search' => $search, 'academic_status' => $filterAcademic, 'employment_type' => $filterEmployment], fn ($v) => $v !== '');
@endphp
@include('partials.pagination', [
    'pageUrl' => fn ($p) => route($route, $query + ['page' => $p]),
    'label' => 'Personnel pages',
])
