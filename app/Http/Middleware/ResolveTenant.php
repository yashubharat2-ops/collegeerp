<?php

namespace App\Http\Middleware;

use App\Models\College;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * Session key holding the elevated college selection that was authorized at the
     * switch boundary. Written only by CollegeSwitchController.
     */
    public const GRANT_KEY = 'active_college_grant';

    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);
        if (! $request->user()) return $next($request);
        // Session-aware resolution: stateful (web / stateful-API) requests carry the
        // explicitly selected college. Stateless requests simply have no selection and
        // fall back to the user's default college below — tenant resolution still runs.
        $selected = $request->hasSession() ? $request->session()->get('active_college_id') : null;
        $college = $selected ? $request->user()->colleges()->whereKey($selected)->first() : $request->user()->colleges()->wherePivot('is_default', true)->first();

        // A college the user is not a member of may only become the active tenant when
        // the elevated selection was explicitly authorized at the switch boundary
        // (CollegeSwitchController). A raw `active_college_id` in the session is not a
        // grant on its own, so it can never be used to reach another college's data.
        if (! $college && $selected && $request->user()->isSuperAdmin() && $this->selectionWasGranted($request, $selected)) {
            $college = College::query()->whereKey($selected)->where('status', 'active')->first();
        }

        if (! $college && $selected) abort(403, 'You are not authorized to use this college context.');
        if (! $college && ! $request->user()->isSuperAdmin()) abort(403, 'No college access has been assigned.');
        if ($college) $context->set($college, true);
        return $next($request);
    }

    /**
     * Whether the active selection was granted by an authorization check performed
     * for this exact user and college in CollegeSwitchController.
     */
    private function selectionWasGranted(Request $request, int|string $selected): bool
    {
        $grant = $request->session()->get(self::GRANT_KEY);

        return is_array($grant)
            && (int) ($grant['college_id'] ?? 0) === (int) $selected
            && (int) ($grant['user_id'] ?? 0) === (int) $request->user()->getKey();
    }
}
