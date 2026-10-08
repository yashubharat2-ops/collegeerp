<?php

namespace App\Http\Controllers;

use App\Http\Requests\Department\StoreDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentStatusRequest;
use App\Models\Campus;
use App\Models\Department;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DepartmentController extends Controller
{
    private const AUDITED = ['id', 'name', 'code', 'campus_id', 'description', 'status'];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Department::class);

        $query = Department::query()->with('campus')->orderBy('name');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function (mixed $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
            });
        }

        if (in_array($request->input('status'), ['active', 'inactive'], true)) {
            $query->where('status', $request->input('status'));
        }

        return view('departments.index', [
            'departments' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'status' => $request->input('status'),
        ]);
    }

    /**
     * CSV export of a bulk selection from the Staff Departments list.
     *
     * Ids are treated as a request, never as data (normalised, capped, re-queried
     * inside the active college through the model's college scope) and
     * `departments.view` is re-checked here. Departments are the shared Platform
     * master used by the Academic and the HR screens, so this screen is
     * export-only.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', Department::class);

        $ids = ListSelection::ids($request->input('ids', []));

        $departments = Department::query()
            ->with('campus')
            ->whereIn('departments.id', $ids)
            ->orderBy('departments.id')
            ->get();

        $rows = $departments->map(fn (Department $department): array => [
            $department->name,
            $department->code,
            $department->campus?->name ?? 'College level',
            $department->status,
            $department->description,
        ]);

        $audit->record('staff_departments.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $departments->count(),
        ]);

        return CsvStreamExport::make('staff-departments-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders(['Name', 'Code', 'Campus', 'Status', 'Description'])
            ->streamFromCollection($rows);
    }

    public function create(): View
    {
        $this->authorize('create', Department::class);

        return view('departments.create', ['campuses' => $this->campusOptions()]);
    }

    public function store(StoreDepartmentRequest $request, AuditLogService $audit): RedirectResponse
    {
        // college_id is not part of validated(): BelongsToCollege attaches the
        // server-side tenant context when the record is created.
        $department = Department::create($request->validated());
        $audit->record('department.created', $department, [], $department->only(self::AUDITED));

        return back()->with('success', 'Department created.');
    }

    public function edit(string $department): View
    {
        $model = $this->findScoped($department);
        $this->authorize('update', $model);

        return view('departments.edit', [
            'department' => $model,
            'campuses' => $this->campusOptions(),
        ]);
    }

    public function update(UpdateDepartmentRequest $request, string $department, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($department);
        $old = $model->only(self::AUDITED);
        $model->update($request->validated());
        $audit->record('department.updated', $model, $old, $model->only(self::AUDITED));

        return back()->with('success', 'Department updated.');
    }

    public function updateStatus(UpdateDepartmentStatusRequest $request, string $department, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($department);
        $old = $model->only(['status']);
        $model->update($request->validated());
        $audit->record('department.status_changed', $model, $old, $model->only(['status']));

        return back()->with('success', 'Department status updated.');
    }

    public function destroy(string $department, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($department);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('department.deleted', $model, $snapshot, []);

        return back()->with('success', 'Department deleted.');
    }

    /**
     * Tenant-safe lookup: Department's CollegeScope is driven by the server-side
     * TenantContext (resolved by the 'tenant' middleware), so a record belonging
     * to another college is simply not found. Records are resolved here — not by
     * implicit route-model binding — because the tenant middleware runs after
     * SubstituteBindings in the web stack, exactly like the other Phase 0 modules
     * that never rely on bound models in routes.
     */
    private function findScoped(string $department): Department
    {
        return Department::query()->findOrFail($department);
    }

    private function campusOptions()
    {
        // Campus::query() is college-scoped by its own global scope: only the
        // active college's campuses can ever be offered or attached.
        return Campus::query()->orderBy('name')->get(['id', 'name', 'code']);
    }
}
