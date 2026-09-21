<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\ExamResult;
use App\Models\Examination;
use App\Models\Program;
use App\Models\Section;
use App\Services\Examinations\ResultQueryService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Results — the administrative result viewing layer (Examinations Phase 3).
 *
 * Read-only by design: this controller displays CALCULATED result data and
 * never creates marks or results. The data chain it reads is
 *
 *   Examination → ExamSchedule → ExamMark → (calculation) → ExamResult
 *
 * Unpublished results are only listed / shown for users who hold the
 * dedicated `results.view_unpublished` permission — an unpublished result is
 * never presented as a published one.
 *
 * Tenant isolation: ExamResult carries CollegeScope, so every query resolves
 * inside the active college only.
 */
class ResultController extends Controller
{
    public function __construct(private readonly ResultQueryService $query)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ExamResult::class);

        $canViewUnpublished = $request->user()?->can('viewUnpublished', ExamResult::class) ?? false;

        return view('results.index', array_merge($this->filterOptions(), [
            'results' => $this->query->paginate($request, $canViewUnpublished),
            'filters' => $this->query->currentFilters($request),
            'canViewUnpublished' => $canViewUnpublished,
            'resultStatuses' => ExamResult::RESULT_STATUSES,
            'calculationStatuses' => ExamResult::CALCULATION_STATUSES,
            'publicationStatuses' => ExamResult::PUBLICATION_STATUSES,
        ]));
    }

    public function show(string $result): View
    {
        $model = ExamResult::query()->findOrFail($result);

        $this->authorize('view', $model);

        $model->load([
            'examination',
            'academicYear',
            'academicTerm',
            'gradeScale',
            'studentEnrollment.student',
            'studentEnrollment.program',
            'studentEnrollment.section',
            'items.examSchedule.subject',
            'calculatedBy',
            'publishedBy',
        ]);

        return view('results.show', [
            'result' => $model,
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
