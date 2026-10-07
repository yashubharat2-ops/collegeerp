<?php

namespace App\Http\Controllers;

use App\Http\Requests\SalaryStructure\StoreSalaryStructureRequest;
use App\Http\Requests\SalaryStructure\UpdateSalaryStructureRequest;
use App\Models\SalaryStructure;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalaryStructureController extends Controller
{
    private const AUDITED = ['id', 'name', 'code', 'description', 'effective_from', 'effective_to', 'status'];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', SalaryStructure::class);
        $query = SalaryStructure::query()->withCount('components')->orderBy('name');
        if ($request->filled('search')) $query->where(fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%')->orWhere('code', 'like', '%'.$request->input('search').'%'));
        if (in_array($request->input('status'), SalaryStructure::STATUSES, true)) $query->where('status', $request->input('status'));
        return view('salary_structures.index', ['structures' => $query->paginate(20)->withQueryString(), 'filters' => $request->only(['search', 'status']), 'statuses' => SalaryStructure::STATUSES]);
    }

    /**
     * CSV export of a bulk selection from the Staff Salary / Payroll screen.
     *
     * Ids are treated as a request, never as data (normalised, capped, re-queried
     * inside the active college through the model's college scope) and
     * `salary_structures.view` is re-checked here. The CSV carries the structure
     * definition and its component count — never an employee's pay figures — and
     * no payroll calculation runs: pay is produced by PayrollService, one run at
     * a time.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', SalaryStructure::class);

        $ids = ListSelection::ids($request->input('ids', []));

        $structures = SalaryStructure::query()
            ->withCount('components')
            ->whereIn('salary_structures.id', $ids)
            ->orderBy('salary_structures.id')
            ->get();

        $rows = $structures->map(fn (SalaryStructure $structure): array => [
            $structure->name,
            $structure->code,
            $structure->effective_from?->format('Y-m-d'),
            $structure->effective_to?->format('Y-m-d'),
            $structure->components_count,
            $structure->status,
        ]);

        $audit->record('salary_structures.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $structures->count(),
        ]);

        return CsvStreamExport::make('salary-structures-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders(['Name', 'Code', 'Effective from', 'Effective to', 'Components', 'Status'])
            ->streamFromCollection($rows);
    }

    public function create(): View { $this->authorize('create', SalaryStructure::class); return view('salary_structures.create', ['statuses' => SalaryStructure::STATUSES]); }

    public function store(StoreSalaryStructureRequest $request, AuditLogService $audit): RedirectResponse
    {
        $structure = SalaryStructure::create($request->validated() + ['college_id' => $this->collegeId(), 'created_by' => auth()->id(), 'updated_by' => auth()->id()]);
        $audit->record('salary_structure.created', $structure, [], $structure->only(self::AUDITED));
        return redirect()->route('salary-structures.index')->with('success', 'Salary structure created.');
    }

    public function show(string $salaryStructure): View
    {
        $model = $this->findScoped($salaryStructure);
        $this->authorize('view', $model);
        return view('salary_structures.show', ['structure' => $model->load('components')]);
    }

    public function edit(string $salaryStructure): View
    {
        $model = $this->findScoped($salaryStructure);
        $this->authorize('update', $model);
        return view('salary_structures.edit', ['structure' => $model, 'statuses' => SalaryStructure::STATUSES]);
    }

    public function update(UpdateSalaryStructureRequest $request, string $salaryStructure, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($salaryStructure);
        $this->authorize('update', $model);
        $old = $model->only(self::AUDITED);
        $model->update($request->validated() + ['updated_by' => auth()->id()]);
        $audit->record('salary_structure.updated', $model, $old, $model->only(self::AUDITED));
        return redirect()->route('salary-structures.show', $model)->with('success', 'Salary structure updated.');
    }

    public function destroy(string $salaryStructure, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($salaryStructure);
        $this->authorize('delete', $model);
        if ($model->payrolls()->exists()) return back()->withErrors(['salary_structure' => 'A salary structure used by payroll cannot be deleted.']);
        $snapshot = $model->only(self::AUDITED); $model->delete();
        $audit->record('salary_structure.deleted', $model, $snapshot, []);
        return redirect()->route('salary-structures.index')->with('success', 'Salary structure deleted.');
    }

    private function findScoped(string $id): SalaryStructure
    {
        return SalaryStructure::query()->findOrFail($id);
    }

    private function collegeId(): int { return (int) app(TenantContext::class)->require()->getKey(); }
}
