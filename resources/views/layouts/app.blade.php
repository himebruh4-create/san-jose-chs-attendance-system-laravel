<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="app-base" content="{{ url('/') }}">
{{-- Each page's title (already escaped by @section), then the school's short name. --}}
@php($pageTitle = trim($__env->yieldContent('title')))
<title>{!! $pageTitle !== '' ? $pageTitle.' - '.e($school['short_name']) : e($school['short_name']).' Attendance' !!}</title>
<link rel="icon" href="{{ $school['logo_url'] }}">
<link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
<link rel="stylesheet" href="{{ asset_v('css/app-shell.css') }}">
{{-- The school's name and logo for printouts built in the browser (App\Support\SchoolBranding). --}}
<script>window.SCHOOL = @json(\App\Support\SchoolBranding::forScripts());</script>
<script src="{{ asset_v('js/app-shell.js') }}"></script>
@stack('head')
{{-- Last, so its shared screen-size rules can adjust any page's own styles. --}}
<link rel="stylesheet" href="{{ asset_v('css/responsive.css') }}">
</head>
<body data-role="{{ auth()->user()?->role }}" @yield('body-attributes')>

@include('partials.sidebar')

@yield('content')

@auth
    @include('partials.session-guard')
@endauth

@stack('scripts')
</body>
</html>
