@extends('layouts.app')

@section('title', 'Personnel Management - Principal')

@push('head')
<link rel="stylesheet" href="{{ asset_v('css/principal-personnel.css') }}">
<link rel="stylesheet" href="{{ asset_v('css/personnel-cards.css') }}">
<script src="{{ asset('vendor/jsbarcode/JsBarcode.all.min.js') }}"></script>
@endpush

@section('content')
<div class="main">

    <h1>Personnel List</h1>

    @include('partials.personnel-stats')

    <div class="card">

        @include('partials.personnel-filter', ['route' => 'principal.personnel', 'canManage' => false])

        @include('partials.personnel-list', ['route' => 'principal.personnel', 'canManage' => false, 'breakConfig' => null])

    </div>

    @include('partials.personnel-details-modal', ['canManage' => false])

</div>
@endsection

@push('scripts')
<script src="{{ asset_v('js/personnel-cards.js') }}"></script>
@endpush
