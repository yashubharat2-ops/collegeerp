<?php

namespace App\Http\Controllers;

use App\Http\Requests\Program\StoreProgramRequest;
use App\Http\Requests\Program\UpdateProgramRequest;
use App\Models\Department;
use App\Models\Program;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProgramController extends Controller
{
    private const AUDITED = ['id', 'name', 'code', 'short_name', 'department_id', 'description', 'status'];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Program::class);

        $query = Program::query()->with('department')->orderBy('name');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function (mixed $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
            });
        }

        if (in_array($request->input('status'), ['active', 'inactive'], true)) {
            $query->where('status', $request->input('status'));
        }

        return view('programs.index', [
            'programs' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'status' => $request->input('status'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Program::class);

        return view('programs.create', ['departments' => $this->departmentOptions()]);
    }

    public function store(StoreProgramRequest $request, AuditLogService $audit): RedirectResponse
    {
        // college_id is not part of validated(): the Form Request strips any browser
        // value and BelongsToCollege attaches the server-side tenant context when the
        // record is created; department_id was validated to exist in this same college.
        $program = Program::create($request->validated());
        $audit->record('program.created', $program, [], $program->only(self::AUDITED));

        return back()->with('success', 'Program created.');
    }

    public function edit(string $program): View
    {
        $model = $this->findScoped($program);
        $this->authorize('update', $model);

        return view('programs.edit', [
            'program' => $model,
            'departments' => $this->departmentOptions(),
        ]);
    }

    public function update(UpdateProgramRequest $request, string $program, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($program);
        $old = $model->only(self::AUDITED);
        $model->update($request->validated());
        $audit->record('program.updated', $model, $old, $model->only(self::AUDITED));

        return back()->with('success', 'Program updated.');
    }

    public function destroy(string $program, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($program);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('program.deleted', $model, $snapshot, []);

        return back()->with('success', 'Program deleted.');
    }

    /**
     * Tenant-safe lookup: Program's CollegeScope is driven by the server-side
     * TenantContext (resolved by the 'tenant' middleware), so a record belonging
     * to another college is simply not found. Records are resolved here — not by
     * implicit route-model binding — because the tenant middleware runs after
     * SubstituteBindings in the web stack, exactly like the other Phase 0 modules
     * that never rely on bound models in routes.
     */
    private function findScoped(string $program): Program
    {
        return Program::query()->findOrFail($program);
    }

    private function departmentOptions()
    {
        // Department::query() is college-scoped by its own global scope: only the
        // active college's departments can ever be offered or attached.
        return Department::query()->orderBy('name')->get(['id', 'name', 'code']);
    }
}
