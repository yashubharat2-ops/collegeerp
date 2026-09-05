<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(\App\Support\Tenancy\TenantContext::class)->has()) abort(403, 'A college context is required.');
        return $next($request);
    }
}
