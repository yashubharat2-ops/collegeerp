<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Services\Approval\ApprovalRegistry;
use App\Services\Approval\ApprovalService;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

class ApprovalController extends Controller
{
    public function pending(Request $request): View
    {
        $user = $request->user();
        $this->authorize('viewAny', ApprovalRequest::class);

        $collegeId = app(\App\Support\Tenancy\TenantContext::class)->id();

        // Pending approvals visible to this user based on their permissions
        $query = ApprovalRequest::query()
            ->with(['requester'])
            ->where('status', ApprovalRequest::STATUS_PENDING)
            ->orderByDesc('created_at');

        // Filter to only those the user can approve (has required permission)
        $allPending = $query->get();
        $visible = $allPending->filter(function (ApprovalRequest $req) use ($user, $collegeId) {
            if ((int) $req->requested_by === (int) $user->getKey()) {
                return false; // can't approve own, so don't show in pending (show in myRequests)
            }
            $perm = ApprovalRegistry::permissionFor($req->module, $req->action);
            return $perm && $user->hasPermission($perm, $collegeId);
        });

        // Paginate manually for filtered collection? Use query pagination but still enforce filter in view
        // For simplicity, re-query with whereIn for visible ids paginated
        // Fix: pluck returns Collection; whereIn expects flat array – use ->all() to avoid nested array error
        $visibleIds = $visible->pluck('id')->all();
        // Guard empty case to avoid whereIn with empty list generating incorrect SQL; return empty paginator
        if (empty($visibleIds)) {
            $paginated = ApprovalRequest::query()->whereRaw('1=0')->paginate(20)->withQueryString();
        } else {
            $paginated = ApprovalRequest::query()
                ->with(['requester', 'decider'])
                ->whereIn('id', $visibleIds)
                ->orderByDesc('created_at')
                ->paginate(20)->withQueryString();
        }

        return view('approvals.pending', [
            'requests' => $paginated,
        ]);
    }

    public function history(Request $request): View
    {
        if (! $request->user()->can('viewHistory', ApprovalRequest::class)) {
            abort(403);
        }

        $query = ApprovalRequest::query()
            ->with(['requester', 'decider'])
            ->whereIn('status', [ApprovalRequest::STATUS_APPROVED, ApprovalRequest::STATUS_REJECTED])
            ->orderByDesc('decided_at')
            ->orderByDesc('created_at');

        if ($status = $request->input('status')) {
            if (in_array($status, [ApprovalRequest::STATUS_APPROVED, ApprovalRequest::STATUS_REJECTED], true)) {
                $query->where('status', $status);
            }
        }
        if ($module = $request->input('module')) {
            $query->where('module', $module);
        }

        return view('approvals.history', [
            'requests' => $query->paginate(20)->withQueryString(),
            'status' => $request->input('status'),
            'module' => $request->input('module'),
            'modules' => ApprovalRegistry::modulesList(),
        ]);
    }

    public function myRequests(Request $request): View
    {
        $this->authorize('viewAny', ApprovalRequest::class);

        $query = ApprovalRequest::query()
            ->with(['decider'])
            ->where('requested_by', $request->user()->getKey())
            ->orderByDesc('created_at');

        if ($status = $request->input('status')) {
            if (in_array($status, ApprovalRequest::STATUSES, true)) {
                $query->where('status', $status);
            }
        }

        return view('approvals.my_requests', [
            'requests' => $query->paginate(20)->withQueryString(),
            'status' => $request->input('status'),
        ]);
    }

    public function settings(Request $request): View
    {
        $this->authorize('viewAny', \App\Models\ApprovalSetting::class);
        if (! $request->user()->hasPermission('approvals.settings.manage')) {
            abort(403);
        }

        $collegeId = app(\App\Support\Tenancy\TenantContext::class)->id();
        $supported = ApprovalRegistry::allSupported();

        $settingsMap = ApprovalSetting::query()
            ->where('college_id', $collegeId)
            ->get()
            ->keyBy(fn($s) => $s->module . '.' . $s->action);

        return view('approvals.settings', [
            'supported' => $supported,
            'settingsMap' => $settingsMap,
        ]);
    }

    public function updateSettings(Request $request, ApprovalService $service, AuditLogService $audit): RedirectResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('approvals.settings.manage')) {
            abort(403);
        }

        $collegeId = app(\App\Support\Tenancy\TenantContext::class)->id();

        $validated = $request->validate([
            'settings' => ['nullable', 'array'],
            'settings.*.module' => ['required', 'string'],
            'settings.*.action' => ['required', 'string'],
            'settings.*.is_required' => ['required', 'boolean'],
        ]);

        $incoming = $validated['settings'] ?? [];

        foreach ($incoming as $item) {
            $module = $item['module'];
            $action = $item['action'];
            $isRequired = (bool) $item['is_required'];

            if (! ApprovalRegistry::isSupported($module, $action)) {
                continue;
            }

            $setting = ApprovalSetting::withoutGlobalScopes()->firstOrNew([
                'college_id' => $collegeId,
                'module' => $module,
                'action' => $action,
            ]);

            $old = $setting->exists ? $setting->toArray() : [];

            $setting->is_required = $isRequired;
            $setting->label = ApprovalRegistry::labelFor($module, $action);
            $setting->updated_by = $user->getKey();
            if (! $setting->exists) {
                $setting->created_by = $user->getKey();
            }

            $setting->save();

            $audit->record('approval_setting.updated', $setting, $old, $setting->toArray());
        }

        return back()->with('success', 'Approval settings updated.');
    }

    public function approve(Request $request, string $approval, ApprovalService $service): RedirectResponse
    {
        $collegeId = app(\App\Support\Tenancy\TenantContext::class)->id();
        $req = ApprovalRequest::query()->findOrFail($approval);
        $this->authorize('approve', $req);

        $request->validate(['decision_reason' => ['nullable', 'string', 'max:2000']]);

        try {
            $service->approve((int) $req->id, (int) $collegeId, (int) $request->user()->getKey(), $request->input('decision_reason'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return back()->with('success', 'Request approved.');
    }

    public function reject(Request $request, string $approval, ApprovalService $service): RedirectResponse
    {
        $collegeId = app(\App\Support\Tenancy\TenantContext::class)->id();
        $req = ApprovalRequest::query()->findOrFail($approval);
        $this->authorize('reject', $req);

        $request->validate(['decision_reason' => ['required', 'string', 'max:2000']]);

        try {
            $service->reject((int) $req->id, (int) $collegeId, (int) $request->user()->getKey(), $request->input('decision_reason'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return back()->with('success', 'Request rejected.');
    }

    public function cancel(Request $request, string $approval, ApprovalService $service): RedirectResponse
    {
        $collegeId = app(\App\Support\Tenancy\TenantContext::class)->id();
        $req = ApprovalRequest::query()->findOrFail($approval);
        $this->authorize('cancel', $req);

        try {
            $service->cancel((int) $req->id, (int) $collegeId, (int) $request->user()->getKey());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return back()->with('success', 'Request cancelled.');
    }

    public function show(Request $request, string $approval): View
    {
        $model = ApprovalRequest::query()->with(['requester', 'decider'])->findOrFail($approval);
        $this->authorize('view', $model);

        return view('approvals.show', ['request' => $model]);
    }
}
