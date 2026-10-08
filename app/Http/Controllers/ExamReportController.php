<?php

namespace App\Http\Controllers;

use App\Models\Examination;
use App\Models\ExamReport;
use App\Models\Program;
use App\Services\Audit\AuditLogService;
use App\Services\Examinations\ExamReportService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * CSV of the report's selected lines — the PROGRAM-WISE or the SUBJECT-WISE
     * summary, whichever table the selection came from.
     *
     * The report screen has no rows of its own: every figure is a live COUNT over
     * published results. A report line is therefore keyed by the real,
     * college-scoped record it summarises (a Program or a Subject), which is what
     * the listing's checkboxes carry and what the bulk action handler re-queried
     * inside the active college before returning the ids this endpoint receives.
     *
     * Two guarantees keep the CSV identical to what was on screen:
     *
     *  1. the group (`programs` / `subjects`) is validated against a fixed
     *     allow-list — never taken on trust from the URL;
     *  2. the screen's own filters are re-applied, and the examination / program
     *     filter values are re-checked against the active college's option lists
     *     (exactly like `index()`), so a forged filter id yields an empty report
     *     rather than cross-tenant rows.
     *
     * The report stays read-only: nothing here writes a result, a mark or a grade.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', ExamReport::class);

        $group = $request->input('group') === 'subjects' ? 'subjects' : 'programs';

        $examinations = Examination::query()->orderByDesc('id')->get(['id', 'name', 'code']);
        $programs = Program::query()->orderBy('name')->get(['id', 'name', 'code']);

        $examinationId = $this->selectedExaminationId($request, $examinations);
        $programId = $this->optionalFilter($request->input('program_id'), $programs);

        $ids = ListSelection::ids($request->input('ids', []));

        $audit->record('exam_reports.exported', null, [], [
            'group' => $group,
            'examination_id' => $examinationId,
            'program_id' => $programId,
            'selected_ids' => count($ids),
        ]);

        return $group === 'subjects'
            ? $this->subjectExport($this->reports->subjectSummaryRows($ids, $examinationId, $programId))
            : $this->programExport($this->reports->programSummaryRows($ids, $examinationId, $programId));
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function programExport($rows): StreamedResponse
    {
        return CsvStreamExport::make('exam-report-programs-'.now()->format('Y-m-d').'.csv')
            ->withHeaders(['Program', 'Code', 'Total', 'Pass', 'Fail', 'Absent', 'Withheld', 'Incomplete', 'Pass rate %'])
            ->map(fn (object $row): array => [
                $row->name,
                $row->code,
                $row->total,
                $row->pass_count,
                $row->fail_count,
                $row->absent_count,
                $row->withheld_count,
                $row->incomplete_count,
                $row->pass_rate,
            ])
            ->streamFromCollection($rows);
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function subjectExport($rows): StreamedResponse
    {
        return CsvStreamExport::make('exam-report-subjects-'.now()->format('Y-m-d').'.csv')
            ->withHeaders(['Subject', 'Code', 'Max marks', 'Total', 'Pass', 'Fail', 'Absent', 'Withheld', 'Incomplete', 'Pass rate %'])
            ->map(fn (object $row): array => [
                $row->name,
                $row->code,
                $row->max_marks,
                $row->total,
                $row->pass_count,
                $row->fail_count,
                $row->absent_count,
                $row->withheld_count,
                $row->incomplete_count,
                $row->pass_rate,
            ])
            ->streamFromCollection($rows);
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
