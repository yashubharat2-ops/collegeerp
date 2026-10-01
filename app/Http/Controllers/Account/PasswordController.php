<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Services\Audit\AuditLogService;
use Illuminate\Auth\Events\PasswordChanged;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Lets the signed-in user change their own password from the account panel.
 *
 * Two boundaries the guest password screens do not have to care about: the current
 * password must be proved (checked in ChangePasswordRequest against the same hash the
 * login attempt uses), and the account being changed is always the one in the session —
 * the route takes no identifier, so there is nothing to swap. The `hashed` cast on
 * User::password remains the only place a hash is produced, and the audit record keeps
 * no credential value of any kind.
 *
 * The session deliberately survives the change, matching how the rest of the ERP treats
 * a self-service password update: sign-out stays on its own POST route.
 */
class PasswordController extends Controller
{
    public function edit(): View
    {
        return view('account.password');
    }

    public function store(ChangePasswordRequest $request, AuditLogService $audit): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill(['password' => $request->validated('password')])->save();

        event(new PasswordChanged($user));

        $audit->record('account.password_changed', $user, [], [], $request);

        return redirect()
            ->route('password.change.edit')
            ->with('success', 'Your password has been updated.');
    }
}
