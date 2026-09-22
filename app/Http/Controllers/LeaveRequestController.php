<?php

namespace App\Http\Controllers;

use App\Domain\HR\Services\LeaveRequestService;
use App\Http\Requests\LeaveRequest\DecisionLeaveRequest;
use App\Http\Requests\LeaveRequest\StoreLeaveRequestRequest;
use App\Http\Requests\LeaveRequest\UpdateLeaveRequestRequest;
use App\Models\Faculty;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LeaveRequestController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', LeaveRequest::class);
        $filters = $request->validate([
            'faculty_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(LeaveRequest::STATUSES)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $query = LeaveRequest::query()->with(['employee', 'leaveType'])->orderByDesc('from_date')->orderByDesc('id');
        if (! auth()->user()->hasPermission('leave_requests.view')
            && ! auth()->user()->hasPermission('leave_requests.approve')
            && ! auth()->user()->isSuperAdmin()) {
            $query->where('requested_by', auth()->id());
        }
        if ($filters['faculty_id'] ?? null) $query->where('faculty_id', $filters['faculty_id']);
        if ($filters['status'] ?? null) $query->where('status', $filters['status']);
        if ($filters['from'] ?? null) $query->whereDate('to_date', '>=', $filters['from']);
        if ($filters['to'] ?? null) $query->whereDate('from_date', '<=', $filters['to']);
        return view('leave_requests.index', ['requests' => $query->paginate(20)->withQueryString(), 'employees' => $this->employees(), 'statuses' => LeaveRequest::STATUSES, 'filters' => $filters]);
    }

    public function create(): View
    {
        $this->authorize('create', LeaveRequest::class);
        return view('leave_requests.create', ['employees' => $this->employees(), 'leaveTypes' => $this->leaveTypes()]);
    }

    public function store(StoreLeaveRequestRequest $request, LeaveRequestService $service): RedirectResponse
    {
        $service->create($request->validated(), $this->collegeId(), auth()->id());
        return redirect()->route('leave-requests.index')->with('success', 'Leave request submitted for approval.');
    }

    public function edit(string $leaveRequest): View
    {
        $model = $this->findScoped($leaveRequest);
        $this->authorize('update', $model);
        return view('leave_requests.edit', ['leaveRequest' => $model, 'employees' => $this->employees(), 'leaveTypes' => $this->leaveTypes()]);
    }

    public function update(UpdateLeaveRequestRequest $request, string $leaveRequest, LeaveRequestService $service): RedirectResponse
    {
        $model = $this->findScoped($leaveRequest);
        $this->authorize('update', $model);
        $service->update($model, $request->validated(), auth()->id());
        return redirect()->route('leave-requests.index')->with('success', 'Leave request updated.');
    }

    public function approve(DecisionLeaveRequest $request, string $leaveRequest, LeaveRequestService $service): RedirectResponse
    {
        $model = $this->findScoped($leaveRequest);
        $this->authorize('approve', $model);
        $service->approve($model, $this->collegeId(), auth()->id(), $request->validated('approval_remarks'));
        return redirect()->route('leave-requests.index')->with('success', 'Leave request approved.');
    }

    public function reject(DecisionLeaveRequest $request, string $leaveRequest, LeaveRequestService $service): RedirectResponse
    {
        $model = $this->findScoped($leaveRequest);
        $this->authorize('approve', $model);
        $service->reject($model, $this->collegeId(), auth()->id(), $request->validated('approval_remarks'));
        return redirect()->route('leave-requests.index')->with('success', 'Leave request rejected.');
    }

    public function cancel(string $leaveRequest, LeaveRequestService $service): RedirectResponse
    {
        $model = $this->findScoped($leaveRequest);
        $this->authorize('cancel', $model);
        $service->cancel($model, $this->collegeId(), auth()->id());
        return redirect()->route('leave-requests.index')->with('success', 'Leave request cancelled.');
    }

    public function destroy(string $leaveRequest, LeaveRequestService $service): RedirectResponse
    {
        $model = $this->findScoped($leaveRequest);
        $this->authorize('delete', $model);
        $service->delete($model, auth()->id());
        return redirect()->route('leave-requests.index')->with('success', 'Leave request deleted.');
    }

    private function findScoped(string $id): LeaveRequest
    {
        return LeaveRequest::query()->findOrFail($id);
    }

    private function employees()
    {
        return Faculty::query()->where('status', 'active')->orderBy('first_name')->orderBy('last_name')->get(['id', 'employee_code', 'first_name', 'middle_name', 'last_name']);
    }

    private function leaveTypes()
    {
        return LeaveType::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']);
    }

    private function collegeId(): int { return (int) app(TenantContext::class)->require()->getKey(); }
}
