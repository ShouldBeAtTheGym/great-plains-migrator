<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIntroComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = \App\Models\Company::current();
        if (! $request->session()->get('office.preflight') && ! $company->introAcknowledged()) {
            return redirect()->route('start.ready');
        }

        return $next($request);
    }
}
