@extends('layouts.app')

@section('title', 'Personnel List - Admin')

@push('head')
<link rel="stylesheet" href="{{ asset_v('css/admin-personnel.css') }}">
<link rel="stylesheet" href="{{ asset_v('css/personnel-cards.css') }}">
@endpush

@section('content')
<div class="main">

    <div class="page-header">
        <div class="eyebrow">Admin Officer</div>
        <h1>Personnel List</h1>
        <p>Search and view personnel records and schedules managed by Super Admin.</p>
    </div>

    @include('partials.personnel-stats')

    <div class="card">

        <h3><i class="fa-solid fa-users"></i> Personnel List</h3>

        @include('partials.personnel-filter', ['route' => 'admin.personnel', 'canManage' => false])

        @include('partials.personnel-list', ['route' => 'admin.personnel', 'canManage' => false, 'breakConfig' => null])

    </div>

    @include('partials.personnel-details-modal', ['canManage' => false])

</div>
@endsection

@push('scripts')
<script src="{{ asset('vendor/jsbarcode/JsBarcode.all.min.js') }}"></script>
<script src="{{ asset_v('js/personnel-cards.js') }}"></script>
<script src="{{ asset_v('js/live-filters.js') }}"></script>
@endpush
