<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'Great Plains Migrator') }}</title>
        <link rel="stylesheet" href="/css/office.css?v=11">
    </head>
    <body class="font-sans text-gray-900 antialiased" style="background:#f3f6f9;">
        <div class="py-8">
            <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
                <p class="kicker">Great Plains Migrator</p>
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
