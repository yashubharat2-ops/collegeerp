<?php

namespace App\Http\Controllers;

use App\Models\Examination;
use App\Models\ExamReport;
use App\Models\Program;
use App\Services\Examinations\ExamReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Exam Reports — read-only published-result summaries (Examinations Phase 4).
 *
 * The screen aggregates published ExamResult / ExamResultItem data into an
 * examination-wise status summary plus program-wise and subject-wise tables.
 * All figures are simple counts produced by ExamReportService; the controller
 * only resolves the filters and hands ready data to the view.
 *
 * Tenant isolation: every report query is tenant-scoped (plus explicit
 * same-college guards on joined tables), so a forged filter id can only ever
 * produce an empty report — never cross-tenant rows.
 */
class ExamReportController extends Controller
{
    public function __construct(private readonly ExamReportService $reports)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ExamReport::class);

        $examinations = Examination::query()->orderByDesc('id')->get(['id', 'name', 'code']);
        $programs = Program::query()->orderBy('name')->get(['id', 'name', 'code']);

        $examinationId = $this->selectedExaminationId($request, $examinations);
        $programId = $this->optionalFilter($request->input('program_id'), $programs);

        return view('exam_reports.index', [
            'examinations' => $examinations,
            'programs' => $programs,
            'examinationId' => $examinationId,
            'programId' => $programId,
            'summary' => $this->reports->statusSummary($examinationId, $programId),
            'programSummaries' => $this->reports->programSummaries($examinationId, $programId),
            'subjectSummaries' => $this->reports->subjectSummaries($examinationId, $programId),
        ]);
    }

    /**
     * The requested examination when it belongs to the active college,
     * otherwise the latest examination with published results (or null when
     * there is nothing to report on yet).
     */
    private function selectedExaminationId(Request $request, \Illuminate\Support\Collection $examinations): ?int
    {
        $requested = $request->input('examination_id');

        if ($requested !== null && $requested !== '') {
            $id = (int) $requested;

            return $examinations->contains('id', $id) ? $id : -1;
        }

        return $this->reports->defaultExaminationId();
    }

    /**
     * The requested program when it belongs to the active college; an unknown
     * id narrows the report to nothing rather than leaking across tenants.
     */
    private function optionalFilter(mixed $requested, \Illuminate\Support\Collection $options): ?int
    {
        if ($requested === null || $requested === '') {
            return null;
        }

        $id = (int) $requested;

        return $options->contains('id', $id) ? $id : -1;
    }
}
