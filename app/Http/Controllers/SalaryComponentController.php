<?php

namespace App\Http\Controllers;

use App\Http\Requests\SalaryComponent\StoreSalaryComponentRequest;
use App\Http\Requests\SalaryComponent\UpdateSalaryComponentRequest;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalaryComponentController extends Controller
{
    private const AUDITED = ['id', 'salary_structure_id', 'name', 'code', 'component_type', 'calculation_type', 'value', 'basis', 'sort_order', 'status'];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', SalaryComponent::class);
        $query = SalaryComponent::query()->with('structure')->orderBy('salary_structure_id')->orderBy('sort_order')->orderBy('id');
        if ($request->filled('salary_structure_id')) $query->where('salary_structure_id', $request->integer('salary_structure_id'));
        return view('salary_components.index', ['components' => $query->paginate(30)->withQueryString(), 'structures' => SalaryStructure::query()->orderBy('name')->get(['id', 'name']), 'filterStructure' => $request->input('salary_structure_id')]);
    }

    /**
     * CSV export of a bulk selection from the Staff Salary / Payroll screen.
     *
     * Ids are treated as a request, never as data (normalised, capped, re-queried
     * inside the active college through the model's college scope) and
     * `salary_components.view` is re-checked here. These values are the structure
     * configuration the listing shows; no payroll calculation runs and nothing is
     * written.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', SalaryComponent::class);

        $ids = ListSelection::ids($request->input('ids', []));

        $components = SalaryComponent::query()
            ->with('structure')
            ->whereIn('salary_components.id', $ids)
            ->orderBy('salary_components.id')
            ->get();

        $rows = $components->map(fn (SalaryComponent $component): array => [
            $component->structure?->name,
            $component->name,
            $component->code,
            ucfirst((string) $component->component_type),
            ucfirst((string) $component->calculation_type),
            $component->basis ?: 'gross',
            $component->value,
        ]);

        $audit->record('salary_components.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $components->count(),
        ]);

        return CsvStreamExport::make('salary-components-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders([
                'Structure', 'Component', 'Code', 'Component type', 'Calculation',
                'Basis', 'Value',
            ])
            ->streamFromCollection($rows);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', SalaryComponent::class);
        return view('salary_components.create', ['structures' => SalaryStructure::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']), 'selectedStructure' => $request->integer('salary_structure_id') ?: null]);
    }

    public function store(StoreSalaryComponentRequest $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validated();
        $this->assertUniqueCode((int) $data['salary_structure_id'], $data['code']);
        $component = SalaryComponent::create($data + ['college_id' => $this->collegeId()]);
        $audit->record('salary_component.created', $component, [], $component->only(self::AUDITED));
        return redirect()->route('salary-structures.show', $component->salary_structure_id)->with('success', 'Salary component added.');
    }

    public function edit(string $salaryComponent): View
    {
        $model = $this->findScoped($salaryComponent);
        $this->authorize('update', $model);
        return view('salary_components.edit', ['component' => $model, 'structure' => $model->structure]);
    }

    public function update(UpdateSalaryComponentRequest $request, string $salaryComponent, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($salaryComponent);
        $this->authorize('update', $model);
        $data = $request->validated();
        $this->assertUniqueCode((int) $model->salary_structure_id, $data['code'], $model->id);
        $old = $model->only(self::AUDITED);
        $model->update($data);
        $audit->record('salary_component.updated', $model, $old, $model->only(self::AUDITED));
        return redirect()->route('salary-structures.show', $model->salary_structure_id)->with('success', 'Salary component updated.');
    }

    public function destroy(string $salaryComponent, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($salaryComponent);
        $this->authorize('delete', $model);
        if ($model->payrollItems()->exists()) return back()->withErrors(['component' => 'A component used by payroll cannot be deleted.']);
        $snapshot = $model->only(self::AUDITED); $model->delete();
        $audit->record('salary_component.deleted', $model, $snapshot, []);
        return redirect()->route('salary-structures.show', $model->salary_structure_id)->with('success', 'Salary component deleted.');
    }

    private function findScoped(string $id): SalaryComponent
    {
        return SalaryComponent::query()->findOrFail($id);
    }

    private function assertUniqueCode(int $structureId, string $code, ?int $ignore = null): void
    {
        $query = SalaryComponent::withoutGlobalScopes()->where('college_id', $this->collegeId())->where('salary_structure_id', $structureId)->where('code', strtoupper(trim($code)))->whereNull('deleted_at');
        if ($ignore) $query->whereKeyNot($ignore);
        if ($query->exists()) throw ValidationException::withMessages(['code' => 'This code is already used in the selected salary structure.']);
    }

    private function collegeId(): int { return (int) app(TenantContext::class)->require()->getKey(); }
}
