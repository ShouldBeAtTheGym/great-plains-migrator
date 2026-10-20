<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'setup.ready' => \App\Http\Middleware\EnsurePreflightComplete::class,
            'setup.introduced' => \App\Http\Middleware\EnsureIntroComplete::class,
            'intro.open' => \App\Http\Middleware\RedirectIfIntroClosed::class,
            'office.login' => \App\Http\Middleware\EnsureOfficeLogin::class,
        ]);
        $middleware->prependToGroup('web', \App\Http\Middleware\EnsureSessionTables::class);
        $middleware->prependToPriorityList(
            \Illuminate\Session\Middleware\StartSession::class,
            \App\Http\Middleware\EnsureSessionTables::class,
        );
        $middleware->appendToGroup('web', \App\Http\Middleware\EnsureDatabaseReady::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
