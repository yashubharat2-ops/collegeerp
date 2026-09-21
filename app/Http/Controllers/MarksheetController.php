<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Examination;
use App\Models\ExamResult;
use App\Models\Marksheet;
use App\Models\Program;
use App\Models\Section;
use App\Services\Examinations\ResultQueryService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Marksheets — printable published examination results (Examinations Phase 4A).
 *
 * Read-only by design: this controller displays PUBLISHED calculated result
 * data as an academic marksheet and never creates marks or results. The data
 * chain it reads is
 *
 *   Examination → ExamSchedule → ExamMark → (calculation) → ExamResult
 *
 * and the read path is shared with the Results module through
 * ResultQueryService, always with unpublished rows excluded — there is no
 * second result query or calculation system here.
 *
 * Tenant isolation: ExamResult carries CollegeScope, so every query resolves
 * inside the active college only; cross-college ids 404 via findOrFail.
 */
class MarksheetController extends Controller
{
    public function __construct(private readonly ResultQueryService $query)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Marksheet::class);

        // Marksheets are published-only: unlike the administrative Results
        // list, there is no unpublished visibility, whatever the request says.
        $filters = $this->query->currentFilters($request);

        return view('marksheets.index', array_merge($this->filterOptions(), [
            'results' => $this->query->paginate($request, false),
            'filters' => $filters,
            'resultStatuses' => ExamResult::RESULT_STATUSES,
        ]));
    }

    public function show(string $result, TenantContext $tenant): View
    {
        $model = ExamResult::query()->findOrFail($result);

        $this->authorize('view', Marksheet::fromResult($model));

        $model->load([
            'examination',
            'academicYear',
            'academicTerm',
            'gradeScale',
            'studentEnrollment.student',
            'studentEnrollment.program',
            'studentEnrollment.section',
            'items.examSchedule.subject',
            'items.subject',
            'calculatedBy',
            'publishedBy',
        ]);

        return view('marksheets.show', [
            'result' => $model,
            'college' => $tenant->college(),
        ]);
    }

    private function filterOptions(): array
    {
        return [
            'examinations' => Examination::query()->orderByDesc('id')->get(['id', 'name', 'code']),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'academicTerms' => AcademicTerm::query()->orderBy('sequence')->get(['id', 'name', 'code']),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'sections' => Section::query()->orderBy('name')->get(['id', 'name', 'code']),
        ];
    }
}
