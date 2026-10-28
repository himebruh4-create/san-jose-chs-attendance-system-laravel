<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="app-base" content="{{ url('/') }}">
<title>Signed In on Another Device</title>
<link rel="icon" href="{{ asset('img/logo.png') }}">
<link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
<link rel="stylesheet" href="{{ asset_v('css/app-shell.css') }}">
<script src="{{ asset_v('js/app-shell.js') }}"></script>
</head>
{{-- Shown instead of any page while this session, replaced by another
     device, has not answered the prompt (EnsureSingleActiveSession). --}}
<body class="session-replaced-page">

@include('partials.session-guard', ['checkNow' => true])

</body>
</html>
