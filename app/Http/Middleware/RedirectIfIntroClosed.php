<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfIntroClosed
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() && User::query()->exists()) {
            return redirect()->guest(route('login'));
        }

        $company = Company::current();
        if ($company->introClosed()) {
            return redirect()->to($company->resumePath());
        }

        return $next($request);
    }
}
