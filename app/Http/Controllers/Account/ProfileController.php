<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The signed-in user's own profile, reached from the header account panel.
 *
 * The account a row belongs to is always `$request->user()`: there is no user
 * identifier in the route, so this controller structurally cannot open or edit anybody
 * else's record. Role, permission, status and college membership are read here through
 * the same relations the rest of the application uses, and are rendered read-only —
 * changing them stays inside the Administration module, behind User policies.
 */
class ProfileController extends Controller
{
    public function edit(Request $request, TenantContext $tenancy): View
    {
        return view('account.profile', [
            'profileUser' => $request->user(),
            'activeCollege' => $tenancy->college(),
            'assignments' => $this->assignmentsFor($request->user()),
        ]);
    }

    public function update(UpdateProfileRequest $request, AuditLogService $audit): RedirectResponse
    {
        $user = $request->user();

        // Beyond the FormRequest there is a second boundary: fill() only accepts the
        // keys the model itself lists as fillable, so name and e-mail are the only two
        // values this method can ever write, whatever the request carried.
        $user->fill($request->validated());

        if (! $user->isDirty()) {
            // The same name and the same address came back. Nothing is written, so
            // `updated_at` stays where it was and, more importantly, no
            // account.profile_updated row is produced for a change that did not happen.
            return redirect()
                ->route('profile.edit')
                ->with('success', 'Your profile is up to date.');
        }

        // Only the fields that actually moved are audited: getDirty() is the pending
        // change set, getRawOriginal() the stored value of those same keys and
        // getChanges() what the write reported back (its own updated_at is not part of
        // the pair). The college this happened in is added by the audit service from
        // TenantContext — which is why these routes run behind `tenant`.
        $changed = array_flip(array_keys($user->getDirty()));
        $before = array_intersect_key($user->getRawOriginal(), $changed);

        $user->save();

        $after = array_intersect_key($user->getChanges(), $changed);

        $audit->record('account.profile_updated', $user, $before, $after, $request);

        return redirect()
            ->route('profile.edit')
            ->with('success', 'Your profile has been updated.');
    }

    /**
     * The user's role assignments with the college each one is scoped to.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Role>
     */
    private function assignmentsFor(User $user)
    {
        return $user->roles()
            ->with('college')
            ->withCount('permissions')
            ->orderByRaw('coalesce(role_user.college_id, 0)')
            ->orderBy('roles.name')
            ->get();
    }
}
