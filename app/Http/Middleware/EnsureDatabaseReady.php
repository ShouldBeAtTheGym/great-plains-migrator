<?php

namespace App\Http\Middleware;

use App\Services\DatabaseInstaller;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDatabaseReady
{
    public function __construct(private DatabaseInstaller $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->installer->ready() || $request->routeIs('product', 'install.store')) {
            return $next($request);
        }

        return redirect()->route('product');
    }
}
