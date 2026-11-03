<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Favicons -->
        <link rel="icon" href="/Govern/Govern-favicon.ico" sizes="any">
        <link rel="icon" href="/Govern/Govern-favicon.svg" type="image/svg+xml">
        <link rel="icon" href="/Govern/Govern-favicon-32.png" type="image/png" sizes="32x32">
        <link rel="icon" href="/Govern/Govern-favicon-16.png" type="image/png" sizes="16x16">
        <link rel="apple-touch-icon" href="/Govern/Govern-apple-touch-icon-180.png">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
