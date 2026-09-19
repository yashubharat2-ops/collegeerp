<?php

namespace App\Http\Controllers;

use App\Http\Requests\Faculty\StoreFacultyRequest;
use App\Http\Requests\Faculty\UpdateFacultyRequest;
use App\Models\Department;
use App\Models\Faculty;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
        'designation',
        'department_id',
        'employment_type',
        'status',
        'joining_date',
    ];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Faculty::class);

        $query = Faculty::query()
            ->with('department')
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
                    ->orWhere('designation', 'like', "%{$search}%");
            });
        }

        if ($departmentId = $request->input('department_id')) {
            $query->where('department_id', $departmentId);
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
            'employment_type' => $request->input('employment_type'),
            'status' => $request->input('status'),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name', 'code']),
            'employmentTypes' => Faculty::EMPLOYMENT_TYPES,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Faculty::class);

        return view('faculties.create', [
            'departments' => Department::query()->orderBy('name')->get(['id', 'name', 'code']),
            'employmentTypes' => Faculty::EMPLOYMENT_TYPES,
        ]);
    }

    public function store(StoreFacultyRequest $request, AuditLogService $audit): RedirectResponse
    {
        $faculty = Faculty::create($request->validated() + [
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $audit->record('faculty.created', $faculty, [], $faculty->only(self::AUDITED));

        return redirect()->route('faculties.index')->with('success', 'Faculty member created.');
    }

    public function edit(string $faculty): View
    {
        $model = $this->findScoped($faculty);
        $this->authorize('update', $model);

        return view('faculties.edit', [
            'faculty' => $model,
            'departments' => Department::query()->orderBy('name')->get(['id', 'name', 'code']),
            'employmentTypes' => Faculty::EMPLOYMENT_TYPES,
        ]);
    }

    public function update(UpdateFacultyRequest $request, string $faculty, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($faculty);
        $old = $model->only(self::AUDITED);

        $model->update($request->validated() + ['updated_by' => auth()->id()]);
        $audit->record('faculty.updated', $model, $old, $model->only(self::AUDITED));

        return redirect()->route('faculties.index')->with('success', 'Faculty member updated.');
    }

    public function destroy(string $faculty, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($faculty);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('faculty.deleted', $model, $snapshot, []);

        return redirect()->route('faculties.index')->with('success', 'Faculty member deleted.');
    }

    private function findScoped(string $id): Faculty
    {
        return Faculty::query()->findOrFail($id);
    }
}
