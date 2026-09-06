<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveTenant;
use App\Models\College;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CollegeSwitchController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['college_id' => ['required', 'integer']]);
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            $college = College::query()
                ->whereKey($data['college_id'])
                ->where('status', 'active')
                ->firstOrFail();
        } else {
            $college = $this->resolveAssignedActiveCollege($user, $data['college_id']);
        }

        $request->session()->put('active_college_id', $college->getKey());

        // Record the authorization decision for colleges the user is not a member of,
        // so ResolveTenant can honour the elevated selection on subsequent requests
        // without re-deriving (or implicitly assuming) the privilege.
        if ($user->colleges()->whereKey($college->getKey())->exists()) {
            $request->session()->forget(ResolveTenant::GRANT_KEY);
        } else {
            $request->session()->put(ResolveTenant::GRANT_KEY, [
                'user_id' => $user->getKey(),
                'college_id' => $college->getKey(),
            ]);
        }

        return back()->with('success', 'College context switched.');
    }

    /**
     * Non-super-admin users may only switch to an active college they belong to.
     *
     * An active college the user is not assigned to is an authorization denial
     * (403 Forbidden), not a missing resource (404 Not Found). Only colleges
     * that do not exist or are not active remain 404.
     */
    private function resolveAssignedActiveCollege(User $user, int $collegeId): College
    {
        $college = College::query()
            ->whereKey($collegeId)
            ->where('status', 'active')
            ->first();

        if (! $college) {
            abort(404);
        }

        if (! $user->colleges()->whereKey($college->getKey())->exists()) {
            abort(403, 'You are not authorized to switch to this college.');
        }

        return $college;
    }
}
