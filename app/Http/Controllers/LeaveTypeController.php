<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeaveType\StoreLeaveTypeRequest;
use App\Http\Requests\LeaveType\UpdateLeaveTypeRequest;
use App\Models\LeaveType;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeaveTypeController extends Controller
{
    private const AUDITED = ['id', 'name', 'code', 'description', 'max_days_per_year', 'status'];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', LeaveType::class);
        $query = LeaveType::query()->withCount('requests')->orderBy('name');
        if ($request->filled('search')) $query->where(fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%')->orWhere('code', 'like', '%'.$request->input('search').'%'));
        if (in_array($request->input('status'), LeaveType::STATUSES, true)) $query->where('status', $request->input('status'));
        return view('leave_types.index', ['leaveTypes' => $query->paginate(20)->withQueryString(), 'filters' => $request->only(['search', 'status']), 'statuses' => LeaveType::STATUSES]);
    }

    public function create(): View { $this->authorize('create', LeaveType::class); return view('leave_types.create', ['statuses' => LeaveType::STATUSES]); }

    public function store(StoreLeaveTypeRequest $request, AuditLogService $audit): RedirectResponse
    {
        $type = LeaveType::create($request->validated() + ['college_id' => $this->collegeId(), 'created_by' => auth()->id(), 'updated_by' => auth()->id()]);
        $audit->record('leave_type.created', $type, [], $type->only(self::AUDITED));
        return redirect()->route('leave-types.index')->with('success', 'Leave type created.');
    }

    public function edit(string $leaveType): View
    {
        $model = $this->findScoped($leaveType);
        $this->authorize('update', $model);
        return view('leave_types.edit', ['leaveType' => $model, 'statuses' => LeaveType::STATUSES]);
    }

    public function update(UpdateLeaveTypeRequest $request, string $leaveType, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($leaveType);
        $this->authorize('update', $model);
        $old = $model->only(self::AUDITED);
        $model->update($request->validated() + ['updated_by' => auth()->id()]);
        $audit->record('leave_type.updated', $model, $old, $model->only(self::AUDITED));
        return redirect()->route('leave-types.index')->with('success', 'Leave type updated.');
    }

    public function destroy(string $leaveType, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($leaveType);
        $this->authorize('delete', $model);
        if ($model->requests()->exists()) return back()->withErrors(['leave_type' => 'A leave type used by requests cannot be deleted.']);
        $snapshot = $model->only(self::AUDITED); $model->delete();
        $audit->record('leave_type.deleted', $model, $snapshot, []);
        return redirect()->route('leave-types.index')->with('success', 'Leave type deleted.');
    }

    private function findScoped(string $id): LeaveType
    {
        return LeaveType::query()->findOrFail($id);
    }

    private function collegeId(): int { return (int) app(TenantContext::class)->require()->getKey(); }
}
