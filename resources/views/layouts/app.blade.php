<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="app-base" content="{{ url('/') }}">
<title>@yield('title', 'San Jose CHS Attendance')</title>
<link rel="icon" href="{{ asset('img/logo.png') }}">
<link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
<link rel="stylesheet" href="{{ asset_v('css/app-shell.css') }}">
<script src="{{ asset_v('js/app-shell.js') }}"></script>
@stack('head')
</head>
<body data-role="{{ auth()->user()?->role }}" @yield('body-attributes')>

@include('partials.sidebar')

@yield('content')

@stack('scripts')
</body>
</html>
