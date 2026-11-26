<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title')</title>
    @if (file_exists(public_path('build/manifest.json')))
        @vite(['resources/css/site.css', 'resources/js/site.js'])
    @endif
</head>
<body class="bg-page text-base">
    <main class="mx-auto max-w-lg px-4 py-12 md:px-8 md:py-20">
        @yield('content')
    </main>
</body>
</html>
