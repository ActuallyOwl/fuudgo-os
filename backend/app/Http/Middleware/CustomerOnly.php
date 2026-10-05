<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomerOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->role === 'customer', 403, 'Customer access is required.');
        return $next($request);
    }
}
