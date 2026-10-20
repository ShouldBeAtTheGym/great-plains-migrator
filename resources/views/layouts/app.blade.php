<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <link rel="stylesheet" href="/css/office.css?v=13">
        <script>
            document.addEventListener('mouseover', function (event) {
                var hint = event.target.closest('.hover-hint');
                if (!hint) return;
                var tip = hint.querySelector('.hover-tip');
                if (!tip) return;
                var box = hint.getBoundingClientRect();
                var width = Math.min(352, window.innerWidth - 32);
                tip.style.width = width + 'px';
                tip.style.left = Math.max(16, Math.min(box.left, window.innerWidth - width - 16)) + 'px';
                tip.style.top = (box.bottom + 8) + 'px';
            });
        </script>
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>
        <div class="celebrate-overlay" id="celebrate-overlay" hidden>
            <canvas id="celebrate-canvas"></canvas>
            <div class="celebrate-card">
                <p class="celebrate-kicker">Don don</p>
                <h2 id="celebrate-title">Step complete</h2>
                <p id="celebrate-copy">The next Start button is lit.</p>
            </div>
        </div>
        <script src="/js/celebrate.js"></script>
    </body>
</html>
