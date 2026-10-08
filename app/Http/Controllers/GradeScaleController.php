<?php

namespace App\Http\Controllers;

use App\Http\Requests\GradeScale\StoreGradeScaleRequest;
use App\Http\Requests\GradeScale\UpdateGradeScaleRequest;
use App\Models\GradeScale;
use App\Models\GradeScaleItem;
use App\Services\Audit\AuditLogService;
use App\Services\Examinations\GradeScaleService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * CSV of the grade / pass-fail configuration, or of an authorized selection.
     *
     * Destination of the listing's bulk "Export selected" action. One CSV row per
     * configured GRADE BAND (the unit the screen actually prints), each carrying
     * its scale's name, code and status; a scale that has no bands yet still
     * appears as a single row with empty band columns, so a selection is never
     * silently short of a record.
     *
     * Ids were re-queried inside the active college and re-authorized through
     * GradeScalePolicy by the bulk action handler; they are shape-checked
     * (ListSelection) and re-resolved inside the tenant scope here, so a foreign
     * college's scale can never be exported. Read-only: bands are validated for
     * contiguity and overlaps by GradeScaleService on the single-record write
     * path, and this endpoint writes nothing.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', GradeScale::class);

        $ids = ListSelection::ids($request->input('ids', []));

        // A college configures a handful of scales, so the export can be built in
        // memory (bands included) and served through the shared CSV writer. The
        // id list is capped by ListSelection, so a hand-crafted request cannot
        // grow this beyond a bounded set of rows.
        $scales = GradeScale::query()
            ->with(['items'])
            ->when($ids !== [], fn ($query) => $query->whereIn('grade_scales.id', $ids))
            ->orderBy('grade_scales.id')
            ->get();

        $rows = $scales->flatMap(function (GradeScale $scale) {
            if ($scale->items->isEmpty()) {
                return [[$scale->name, $scale->code, $scale->status, null, null, null, null, null, $scale->description]];
            }

            return $scale->items
                ->map(fn ($item): array => [
                    $scale->name,
                    $scale->code,
                    $scale->status,
                    $item->grade,
                    $item->min_percentage,
                    $item->max_percentage,
                    $item->grade_point,
                    $item->status,
                    $item->description,
                ])
                ->all();
        });

        $audit->record('grade_scales.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $scales->count(),
        ]);

        return CsvStreamExport::make('grade-scales-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders([
                'Grade scale', 'Scale code', 'Scale status', 'Grade', 'Min %', 'Max %',
                'Grade point', 'Band status', 'Description',
            ])
            ->streamFromCollection($rows);
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
