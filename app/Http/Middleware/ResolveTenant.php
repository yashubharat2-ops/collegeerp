<?php

namespace App\Http\Middleware;

use App\Models\College;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);
        if (! $request->user()) return $next($request);
        $selected = $request->session()->get('active_college_id');
        $college = $selected ? $request->user()->colleges()->whereKey($selected)->first() : $request->user()->colleges()->wherePivot('is_default', true)->first();
        if (! $college && $request->user()->isSuperAdmin() && $selected) $college = College::find($selected);
        if (! $college && ! $request->user()->isSuperAdmin()) abort(403, 'No college access has been assigned.');
        if ($college) $context->set($college, true);
        return $next($request);
    }
}
