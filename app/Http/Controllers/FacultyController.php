<?php

namespace App\Http\Controllers;

use App\Http\Requests\Faculty\StoreFacultyRequest;
use App\Http\Requests\Faculty\UpdateFacultyRequest;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Faculty;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Platform Faculty/Staff and HR Employee management share this controller and
 * the same `faculties` table. The `employees.*` and `faculties.*` routes are
 * two vocabulary-compatible entry points, not two sets of records.
 */
class FacultyController extends Controller
{
    private const AUDITED = [
        'id',
        'employee_code',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'phone',
        'alternate_phone',
        'gender',
        'date_of_birth',
        'designation',
        'designation_id',
        'department_id',
        'employment_type',
        'status',
        'joining_date',
        'employment_end_date',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'emergency_contact_name',
        'emergency_contact_phone',
        'notes',
    ];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Faculty::class);

        $query = Faculty::query()
            ->with(['department', 'designationMaster'])
            ->orderBy('first_name')
            ->orderBy('last_name');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('employee_code', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('middle_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('designation', 'like', "%{$search}%")
                    ->orWhereHas('designationMaster', fn ($designation) => $designation->where('name', 'like', "%{$search}%"));
            });
        }

        if ($departmentId = $request->input('department_id')) {
            $query->where('department_id', $departmentId);
        }

        if ($designationId = $request->input('designation_id')) {
            $query->where('designation_id', $designationId);
        }

        if ($empType = $request->input('employment_type')) {
            if (in_array($empType, Faculty::EMPLOYMENT_TYPES, true)) {
                $query->where('employment_type', $empType);
            }
        }

        if (in_array($request->input('status'), Faculty::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return view('faculties.index', [
            'faculties' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'department_id' => $request->input('department_id'),
            'designation_id' => $request->input('designation_id'),
            'employment_type' => $request->input('employment_type'),
            'status' => $request->input('status'),
            'departments' => $this->departmentOptions(),
            'designations' => $this->designationOptions(),
            'employmentTypes' => Faculty::EMPLOYMENT_TYPES,
            'isHr' => $this->isHrRoute(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Faculty::class);

        return view('faculties.create', [
            'departments' => $this->departmentOptions(),
            'designations' => $this->designationOptions(),
            'employmentTypes' => Faculty::EMPLOYMENT_TYPES,
            'isHr' => $this->isHrRoute(),
        ]);
    }

    public function store(StoreFacultyRequest $request, AuditLogService $audit): RedirectResponse
    {
        $data = $this->withNormalizedDesignation($request->validated());
        $faculty = Faculty::create($data + [
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $audit->record($this->auditPrefix().'.created', $faculty, [], $faculty->only(self::AUDITED));

        return redirect()->route($this->indexRoute())->with('success', $this->isHrRoute() ? 'Employee created.' : 'Faculty member created.');
    }

    public function show(string $faculty): View
    {
        $model = $this->findScoped($faculty);
        $this->authorize('view', $model);

        $model->load(['department', 'designationMaster']);
        if (auth()->user()?->hasPermission('employee_documents.view', $model->college_id)) {
            $model->load('documents');
        }

        return view('faculties.show', [
            'faculty' => $model,
            'isHr' => $this->isHrRoute(),
            'canViewEmployeeDocuments' => auth()->user()?->hasPermission('employee_documents.view', $model->college_id),
        ]);
    }

    public function edit(string $faculty): View
    {
        $model = $this->findScoped($faculty);
        $this->authorize('update', $model);

        return view('faculties.edit', [
            'faculty' => $model,
            'departments' => $this->departmentOptions(),
            'designations' => $this->designationOptions($model->designation_id),
            'employmentTypes' => Faculty::EMPLOYMENT_TYPES,
            'isHr' => $this->isHrRoute(),
        ]);
    }

    public function update(UpdateFacultyRequest $request, string $faculty, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($faculty);
        $old = $model->only(self::AUDITED);
        $model->update($this->withNormalizedDesignation($request->validated()) + ['updated_by' => auth()->id()]);
        $audit->record($this->auditPrefix().'.updated', $model, $old, $model->only(self::AUDITED));

        return redirect()->route($this->indexRoute())->with('success', $this->isHrRoute() ? 'Employee updated.' : 'Faculty member updated.');
    }

    public function destroy(string $faculty, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($faculty);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record($this->auditPrefix().'.deleted', $model, $snapshot, []);

        return redirect()->route($this->indexRoute())->with('success', $this->isHrRoute() ? 'Employee deleted.' : 'Faculty member deleted.');
    }

    private function findScoped(string $id): Faculty
    {
        return Faculty::query()->findOrFail($id);
    }

    private function departmentOptions()
    {
        return Department::query()->orderBy('name')->get(['id', 'name', 'code']);
    }

    private function designationOptions(?int $selected = null)
    {
        return Designation::query()
            ->where(function ($query) use ($selected): void {
                $query->where('status', 'active');
                if ($selected) {
                    $query->orWhereKey($selected);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'status']);
    }

    /** Keep the original Platform display column synchronized for compatibility. */
    private function withNormalizedDesignation(array $data): array
    {
        if (array_key_exists('designation_id', $data)) {
            $data['designation'] = filled($data['designation_id'])
                ? Designation::query()->find((int) $data['designation_id'])?->name
                : ($this->isHrRoute() ? null : ($data['designation'] ?? null));
        }

        return $data;
    }

    private function isHrRoute(): bool
    {
        return request()->routeIs('employees.*') || request()->routeIs('staff.*');
    }

    private function indexRoute(): string
    {
        if (request()->routeIs('staff.*')) {
            return 'staff.index';
        }

        return $this->isHrRoute() ? 'employees.index' : 'faculties.index';
    }

    private function auditPrefix(): string
    {
        return $this->isHrRoute() ? 'employee' : 'faculty';
    }
}
