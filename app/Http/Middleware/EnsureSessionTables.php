<?php

namespace App\Http\Middleware;

use App\Services\DatabaseInstaller;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSessionTables
{
    public function __construct(private DatabaseInstaller $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('session.driver') === 'database' && ! $this->installer->ensureSessions()) {
            config([
                'session.driver' => 'file',
                'cache.default' => 'file',
            ]);
        }

        return $next($request);
    }
}
