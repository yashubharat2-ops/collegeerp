<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Services\FeeStructureService;
use App\Http\Requests\FeeStructure\StoreFeeStructureRequest;
use App\Http\Requests\FeeStructure\UpdateFeeStructureRequest;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\FeeStructureItem;
use App\Models\Program;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Fee Structure (Finance / Fees foundation).
 *
 * Thin controller: validation lives in the Form Requests, the structural rules
 * (contextual masters, non-negative amounts, duplicate fee heads, duplicate
 * active codes) live in FeeStructureService, and college_id always comes from
 * the tenant context — never from request data.
 *
 * Only the fee structure foundation is implemented here: collection, receipts,
 * discounts, refunds and reports belong to later phases.
 */
class FeeStructureController extends Controller
{
    public function __construct(private readonly FeeStructureService $feeStructures)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', FeeStructure::class);

        $query = FeeStructure::query()
            ->with(['academicYear', 'program', 'academicTerm', 'items'])
            ->withCount('allItems')
            // Deterministic pagination order.
            ->orderByDesc('academic_year_id')
            ->orderBy('name')
            ->orderBy('id');

        $search = trim((string) $request->input('search'));

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        foreach (['academic_year_id', 'program_id', 'academic_term_id'] as $field) {
            if ($value = $request->input($field)) {
                $query->where($field, $value);
            }
        }

        if (in_array($request->input('status'), FeeStructure::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return view('fee_structures.index', array_merge($this->formData(), [
            'structures' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'academic_year_id' => $request->input('academic_year_id'),
            'program_id' => $request->input('program_id'),
            'academic_term_id' => $request->input('academic_term_id'),
            'status' => $request->input('status'),
        ]));
    }

    public function create(): View
    {
        $this->authorize('create', FeeStructure::class);

        return view('fee_structures.create', $this->formData());
    }

    public function store(StoreFeeStructureRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();

        $structure = $this->feeStructures->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('fee-structures.index')
            ->with('success', "Fee structure \"{$structure->name}\" created.");
    }

    public function edit(string $fee_structure): View
    {
        $structure = $this->findScoped($fee_structure);
        $this->authorize('update', $structure);

        $structure->load(['items']);

        return view('fee_structures.edit', array_merge($this->formData(), [
            'structure' => $structure,
        ]));
    }

    public function update(UpdateFeeStructureRequest $request, string $fee_structure): RedirectResponse
    {
        $structure = $this->findScoped($fee_structure);

        $structure = $this->feeStructures->update($structure, $request->validated(), $request->user());

        return redirect()
            ->route('fee-structures.index')
            ->with('success', "Fee structure \"{$structure->name}\" updated.");
    }

    public function destroy(string $fee_structure): RedirectResponse
    {
        $structure = $this->findScoped($fee_structure);
        $this->authorize('delete', $structure);

        $name = $structure->name;
        $this->feeStructures->delete($structure, request()->user());

        return redirect()
            ->route('fee-structures.index')
            ->with('success', "Fee structure \"{$name}\" deleted.");
    }

    /**
     * Resolve the fee structure INSIDE the controller, under the active tenant.
     *
     * The project never relies on implicit route model binding for tenant-scoped
     * models: SubstituteBindings is a member of the `web` middleware group and
     * therefore runs before the `tenant` middleware, so a binding resolved at
     * that point would see no tenant context and could never match a row.
     */
    private function findScoped(string $id): FeeStructure
    {
        return FeeStructure::query()->findOrFail($id);
    }

    /**
     * The academic masters a structure can reference. They are read through
     * their own college scope, so only the active college's masters are offered.
     */
    private function formData(): array
    {
        return [
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'academicTerms' => AcademicTerm::query()->orderBy('sequence')->get(['id', 'name', 'code', 'academic_year_id']),
            // Optional classification for a fee component (Finance / Fees —
            // Fee Categories); reads through its own college scope.
            'feeCategories' => FeeCategory::query()->orderBy('name')->get(['id', 'name', 'code']),
            'statuses' => FeeStructure::STATUSES,
            'itemStatuses' => FeeStructureItem::STATUSES,
        ];
    }
}
