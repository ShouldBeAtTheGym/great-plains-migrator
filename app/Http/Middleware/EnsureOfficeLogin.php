<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOfficeLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (User::query()->exists() && ! $request->user()) {
            return redirect()->guest(route('login'));
        }

        return $next($request);
    }
}
