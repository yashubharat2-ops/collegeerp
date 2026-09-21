<?php

namespace App\Http\Controllers;

use App\Http\Requests\GradeScale\StoreGradeScaleRequest;
use App\Http\Requests\GradeScale\UpdateGradeScaleRequest;
use App\Models\GradeScale;
use App\Models\GradeScaleItem;
use App\Services\Examinations\GradeScaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Grade / Pass-Fail configuration (Examinations Phase 3).
 *
 * Thin controller: validation lives in the Form Requests, the structural rules
 * (ranges, overlaps, duplicates, ordering) live in GradeScaleService, and
 * college_id always comes from the tenant context — never from request data.
 *
 * No grading boundary is hard-coded anywhere in this module: each college owns
 * its own grade scale rows.
 */
class GradeScaleController extends Controller
{
    public function __construct(private readonly GradeScaleService $gradeScales)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', GradeScale::class);

        $scales = GradeScale::query()
            ->withCount('allItems')
            ->with(['items' => fn ($q) => $q->orderBy('sort_order')->orderBy('min_percentage')->orderBy('id')])
            // Deterministic pagination order.
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('grade_scales.index', [
            'scales' => $scales,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', GradeScale::class);

        return view('grade_scales.create', $this->formData());
    }

    public function store(StoreGradeScaleRequest $request): RedirectResponse
    {
        $college = app(\App\Support\Tenancy\TenantContext::class)->require();

        $scale = $this->gradeScales->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('grade-scales.index')
            ->with('success', "Grade scale \"{$scale->name}\" created.");
    }

    public function edit(string $grade_scale): View
    {
        $scale = $this->findScoped($grade_scale);
        $this->authorize('update', $scale);

        $scale->load(['items' => fn ($q) => $q->orderBy('sort_order')->orderBy('min_percentage')->orderBy('id')]);

        return view('grade_scales.edit', array_merge($this->formData(), [
            'scale' => $scale,
        ]));
    }

    public function update(UpdateGradeScaleRequest $request, string $grade_scale): RedirectResponse
    {
        $scale = $this->findScoped($grade_scale);

        $scale = $this->gradeScales->update($scale, $request->validated(), $request->user());

        return redirect()
            ->route('grade-scales.index')
            ->with('success', "Grade scale \"{$scale->name}\" updated.");
    }

    public function destroy(string $grade_scale): RedirectResponse
    {
        $scale = $this->findScoped($grade_scale);
        $this->authorize('delete', $scale);

        $name = $scale->name;
        $this->gradeScales->delete($scale, request()->user());

        return redirect()
            ->route('grade-scales.index')
            ->with('success', "Grade scale \"{$name}\" deleted.");
    }

    /**
     * Resolve the grade scale INSIDE the controller, under the active tenant.
     *
     * The project never relies on implicit route model binding for tenant-scoped
     * models: SubstituteBindings is a member of the `web` middleware group and
     * therefore runs before the `tenant` middleware, so a binding resolved at
     * that point would see no tenant context and could never match a row.
     */
    private function findScoped(string $id): GradeScale
    {
        return GradeScale::query()->findOrFail($id);
    }

    private function formData(): array
    {
        return [
            'statuses' => GradeScale::STATUSES,
            'itemStatuses' => GradeScaleItem::STATUSES,
        ];
    }
}
