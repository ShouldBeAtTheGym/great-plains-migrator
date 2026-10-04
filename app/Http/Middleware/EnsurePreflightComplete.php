<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePreflightComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = Company::current();
        if (! $company->gp_tested_at || ! $company->odoo_tested_at) {
            return redirect()->to($company->setupPath());
        }

        return $next($request);
    }
}
