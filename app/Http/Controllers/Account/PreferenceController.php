<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePreferencesRequest;
use App\Services\Audit\AuditLogService;
use App\Services\Settings\UserPreferenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Interface preferences the signed-in user owns for themselves.
 *
 * Every switch on this page comes from UserPreferenceService::DEFINITIONS, which only
 * lists preferences the application really acts on: the sidebar opens in the state
 * `sidebar.rail_by_default` describes, and the header renders the identity block only
 * when `header.show_identity` is on. A key outside that map has no rule, no field and no
 * writer, so the form cannot advertise or store a setting nothing consumes.
 *
 * Administrator-owned configuration is deliberately absent: institutional settings
 * remain in the Administration module under `admin.settings.*`, and nothing here reads
 * or writes `institutional_settings`. Values are stored per user, with no college
 * identifier involved, so one person's interface choices neither follow nor affect the
 * college they happen to be working in.
 */
class PreferenceController extends Controller
{
    public function edit(Request $request, UserPreferenceService $preferences): View
    {
        return view('account.preferences', [
            'definitions' => UserPreferenceService::DEFINITIONS,
            'values' => $preferences->resolved($request->user()),
        ]);
    }

    public function update(
        UpdatePreferencesRequest $request,
        UserPreferenceService $preferences,
        AuditLogService $audit,
    ): RedirectResponse {
        $user = $request->user();
        $before = $preferences->resolved($user);
        $map = $preferences->fieldMap();

        foreach ($request->validated() as $field => $value) {
            // Defensive: validated() can only contain fields the rule set above built
            // from the allowlist, so an unmapped key cannot reach the service.
            if (isset($map[$field])) {
                $preferences->put($user, $map[$field], $value);
            }
        }

        $after = $preferences->resolved($user);
        if ($before !== $after) {
            $audit->record('account.preferences_updated', $user, $before, $after, $request);
        }

        return redirect()
            ->route('preferences.edit')
            ->with('success', 'Your preferences have been saved.');
    }
}
