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
        $before = ['name' => $user->name, 'email' => $user->email];

        // Beyond the FormRequest there is a second boundary: fill() only accepts the
        // keys the model itself lists as fillable, so name and e-mail are the only two
        // values this method can ever write, whatever the request carried.
        $user->fill($request->validated())->save();

        $after = ['name' => $user->name, 'email' => $user->email];
        if ($before !== $after) {
            $audit->record('account.profile_updated', $user, $before, $after, $request);
        }

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
