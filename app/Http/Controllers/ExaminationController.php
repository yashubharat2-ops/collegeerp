<?php

namespace App\Http\Controllers;

use App\Http\Requests\Examination\StoreExaminationRequest;
use App\Http\Requests\Examination\UpdateExaminationRequest;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Examination;
use App\Services\Audit\AuditLogService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExaminationController extends Controller
{
    private const AUDITED = [
        'id',
        'academic_year_id',
        'academic_term_id',
        'name',
        'code',
        'exam_type',
        'start_date',
        'end_date',
        'status',
        'description',
    ];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Examination::class);

        return view('examinations.index', [
            'examinations' => $this->filteredQuery($request)->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'academic_year_id' => $request->input('academic_year_id'),
            'academic_term_id' => $request->input('academic_term_id'),
            'status' => $request->input('status'),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'academicTerms' => AcademicTerm::query()->orderBy('sequence')->get(['id', 'name', 'code', 'academic_year_id']),
            'statuses' => Examination::STATUSES,
        ]);
    }

    /**
     * The examination list's filter pipeline, in one place.
     *
     * `index()` and `export()` share it, so a CSV can only ever contain what the
     * search / year / term / status filters select — and, when the bulk bar sent
     * an explicit selection, the authorized ids narrow that same set further.
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = Examination::query()
            ->with(['academicYear', 'academicTerm'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('exam_type', 'like', "%{$search}%");
            });
        }

        if ($academicYearId = $request->input('academic_year_id')) {
            $query->where('academic_year_id', $academicYearId);
        }

        if ($academicTermId = $request->input('academic_term_id')) {
            $query->where('academic_term_id', $academicTermId);
        }

        if ($status = $request->input('status')) {
            if (in_array($status, Examination::STATUSES, true)) {
                $query->where('status', $status);
            }
        }

        return $query;
    }

    /**
     * CSV of the filtered examinations, or of an authorized selection of them.
     *
     * Destination of the listing's bulk "Export selected" action: the ids were
     * already re-queried inside the active college and authorized through
     * ExaminationPolicy by the bulk action handler, and they are shape-checked
     * (ListSelection) and re-resolved against the tenant-scoped query here — a
     * hand-edited URL can never widen the download. Read-only: this endpoint
     * streams CSV and writes nothing.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', Examination::class);

        $query = $this->filteredQuery($request)
            // Streamed exports page by primary key, so the on-screen ordering is
            // replaced by a deterministic id order (never by a non-unique date).
            ->reorder('examinations.id');

        $ids = ListSelection::ids($request->input('ids', []));
        if ($ids !== []) {
            $query->whereIn('examinations.id', $ids);
        }

        $audit->record('examinations.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => (clone $query)->count(),
        ]);

        return CsvStreamExport::make('examinations-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders([
                'Name', 'Code', 'Type', 'Academic year', 'Academic term',
                'Start date', 'End date', 'Status', 'Description',
            ])
            ->map(function (Examination $examination): array {
                return [
                    $examination->name,
                    $examination->code,
                    $examination->exam_type,
                    $examination->academicYear?->name,
                    $examination->academicTerm?->name,
                    $examination->start_date?->format('Y-m-d'),
                    $examination->end_date?->format('Y-m-d'),
                    $examination->status,
                    $examination->description,
                ];
            })
            ->streamFromQuery($query);
    }

    public function create(): View
    {
        $this->authorize('create', Examination::class);

        return view('examinations.create', [
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'academicTerms' => AcademicTerm::query()->orderBy('sequence')->get(['id', 'name', 'code', 'academic_year_id']),
            'defaultTypes' => Examination::DEFAULT_EXAM_TYPES,
            'statuses' => Examination::STATUSES,
        ]);
    }

    public function store(StoreExaminationRequest $request, AuditLogService $audit): RedirectResponse
    {
        $examination = Examination::create($request->validated() + [
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $audit->record('examination.created', $examination, [], $examination->only(self::AUDITED));

        return redirect()->route('examinations.index')->with('success', 'Examination created.');
    }

    public function edit(string $examination): View
    {
        $model = $this->findScoped($examination);
        $this->authorize('update', $model);

        return view('examinations.edit', [
            'examination' => $model,
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'academicTerms' => AcademicTerm::query()->orderBy('sequence')->get(['id', 'name', 'code', 'academic_year_id']),
            'defaultTypes' => Examination::DEFAULT_EXAM_TYPES,
            'statuses' => Examination::STATUSES,
        ]);
    }

    public function update(UpdateExaminationRequest $request, string $examination, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($examination);
        $old = $model->only(self::AUDITED);

        $model->update($request->validated() + ['updated_by' => auth()->id()]);
        $audit->record('examination.updated', $model, $old, $model->only(self::AUDITED));

        return redirect()->route('examinations.index')->with('success', 'Examination updated.');
    }

    public function destroy(string $examination, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($examination);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('examination.deleted', $model, $snapshot, []);

        return redirect()->route('examinations.index')->with('success', 'Examination deleted.');
    }

    private function findScoped(string $id): Examination
    {
        return Examination::query()->findOrFail($id);
    }
}
