<?php

namespace App\Http\Controllers;

use App\Domain\HR\Services\PayrollService;
use App\Http\Requests\Payroll\ProcessPayrollRequest;
use App\Models\Faculty;
use App\Models\Payroll;
use App\Models\SalaryStructure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PayrollController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Payroll::class);
        $filters = $request->validate([
            'faculty_id' => ['nullable', 'integer'],
            'pay_period' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', Rule::in(Payroll::STATUSES)],
        ]);
        $query = Payroll::query()->with(['employee', 'salaryStructure'])->orderByDesc('pay_period')->orderBy('faculty_id');
        if ($filters['faculty_id'] ?? null) $query->where('faculty_id', $filters['faculty_id']);
        if ($filters['pay_period'] ?? null) $query->whereDate('pay_period', $filters['pay_period'].'-01');
        if ($filters['status'] ?? null) $query->where('status', $filters['status']);
        return view('payrolls.index', ['payrolls' => $query->paginate(20)->withQueryString(), 'employees' => $this->employees(), 'statuses' => Payroll::STATUSES, 'filters' => $filters]);
    }

    public function create(): View
    {
        $this->authorize('process', Payroll::class);
        return view('payrolls.create', ['employees' => $this->employees(), 'structures' => SalaryStructure::query()->where('status', 'active')->with('components')->orderBy('name')->get()]);
    }

    public function store(ProcessPayrollRequest $request, PayrollService $service): RedirectResponse
    {
        $payroll = $service->process($request->validated(), $this->collegeId(), auth()->id());
        return redirect()->route('payrolls.show', $payroll)->with('success', 'Payroll processed.');
    }

    public function show(string $payroll): View
    {
        $model = $this->findScoped($payroll);
        $this->authorize('view', $model);
        return view('payrolls.show', ['payroll' => $model->load(['employee', 'salaryStructure', 'items'])]);
    }

    public function cancel(string $payroll, PayrollService $service): RedirectResponse
    {
        $model = $this->findScoped($payroll);
        $this->authorize('cancel', $model);
        $service->cancel($model, auth()->id());
        return redirect()->route('payrolls.show', $model)->with('success', 'Payroll cancelled.');
    }

    private function findScoped(string $id): Payroll
    {
        return Payroll::query()->findOrFail($id);
    }

    private function employees()
    {
        return Faculty::query()->where('status', 'active')->orderBy('first_name')->orderBy('last_name')->get(['id', 'employee_code', 'first_name', 'middle_name', 'last_name']);
    }

    private function collegeId(): int { return (int) app(\App\Support\Tenancy\TenantContext::class)->require()->getKey(); }
}
