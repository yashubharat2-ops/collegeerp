<?php

namespace App\Services\Examinations;

use App\Models\Examination;
use App\Models\ExamResult;
use App\Models\ExamResultItem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * ExamReportService — the read side of the Exam Reports screen
 * (Examinations Phase 4).
 *
 * Reports only ever READ published result data: nothing here writes marks,
 * results or grades, and there are no reporting tables. Every figure is a
 * simple COUNT / GROUP BY over the stored ExamResult / ExamResultItem rows
 * (plain portable SQL — no MySQL-only functions), with pass rates derived in
 * PHP from those counts for display.
 *
 * Tenant safety: the base models carry CollegeScope, and every joined table
 * gets an explicit same-college guard plus a soft-delete guard (global scopes
 * do not apply to joined tables), so a forged filter id can only ever produce
 * an empty report — never cross-tenant rows.
 */
class ExamReportService
{
    public function __construct(private readonly TenantContext $tenant)
    {
    }

    /**
     * The examination the report opens on: the latest non-deleted examination
     * that actually has published results in the active college.
     */
    public function defaultExaminationId(): ?int
    {
        $publishedExamIds = ExamResult::query()
            ->whereNotNull('published_at')
            ->distinct()
            ->pluck('examination_id');

        if ($publishedExamIds->isEmpty()) {
            return null;
        }

        return Examination::query()
            ->whereIn('id', $publishedExamIds)
            ->orderByDesc('id')
            ->value('id');
    }

    /**
     * Overall published-result counts for the current filters.
     *
     * @return array{total: int, by_status: array<string, int>, pass_rate: float|null}
     */
    public function statusSummary(?int $examinationId, ?int $programId): array
    {
        $base = $this->filteredResults($examinationId, $programId);

        $total = (clone $base)->count();

        $counts = (clone $base)
            ->selectRaw('result_status, COUNT(*) AS aggregate')
            ->groupBy('result_status')
            ->pluck('aggregate', 'result_status')
            ->all();

        $byStatus = [];
        foreach (ExamResult::RESULT_STATUSES as $status) {
            $byStatus[$status] = (int) ($counts[$status] ?? 0);
        }

        return [
            'total' => $total,
            'by_status' => $byStatus,
            'pass_rate' => $total > 0 ? round($byStatus[ExamResult::RESULT_PASS] * 100 / $total, 1) : null,
        ];
    }

    /**
     * Per-program published-result counts, deterministically paginated.
     */
    public function programSummaries(?int $examinationId, ?int $programId): LengthAwarePaginator
    {
        $collegeId = $this->tenant->id();

        return ExamResult::query()
            ->join('student_enrollments', 'student_enrollments.id', '=', 'exam_results.student_enrollment_id')
            ->join('programs', 'programs.id', '=', 'student_enrollments.program_id')
            ->whereNotNull('exam_results.published_at')
            ->where('student_enrollments.college_id', $collegeId)
            ->whereNull('student_enrollments.deleted_at')
            ->where('programs.college_id', $collegeId)
            ->whereNull('programs.deleted_at')
            ->when($examinationId !== null, fn ($query) => $query->where('exam_results.examination_id', $examinationId))
            ->when($programId !== null, fn ($query) => $query->where('student_enrollments.program_id', $programId))
            ->groupBy('programs.id', 'programs.name', 'programs.code')
            ->selectRaw('programs.id, programs.name, programs.code, COUNT(*) AS total, '.$this->statusSums('exam_results.result_status'))
            ->orderBy('programs.name')
            ->orderBy('programs.id')
            ->paginate(15, ['*'], 'program_page')
            ->withQueryString()
            ->through(fn ($row) => $this->withPassRate($row));
    }

    /**
     * Per-subject published item counts, deterministically paginated.
     */
    public function subjectSummaries(?int $examinationId, ?int $programId): LengthAwarePaginator
    {
        $collegeId = $this->tenant->id();

        return ExamResultItem::query()
            ->join('exam_results', 'exam_results.id', '=', 'exam_result_items.exam_result_id')
            ->join('student_enrollments', 'student_enrollments.id', '=', 'exam_results.student_enrollment_id')
            ->join('subjects', 'subjects.id', '=', 'exam_result_items.subject_id')
            ->whereNotNull('exam_results.published_at')
            ->where('exam_results.college_id', $collegeId)
            ->whereNull('exam_results.deleted_at')
            ->where('student_enrollments.college_id', $collegeId)
            ->whereNull('student_enrollments.deleted_at')
            ->where('subjects.college_id', $collegeId)
            ->whereNull('subjects.deleted_at')
            ->when($examinationId !== null, fn ($query) => $query->where('exam_results.examination_id', $examinationId))
            ->when($programId !== null, fn ($query) => $query->where('student_enrollments.program_id', $programId))
            ->groupBy('subjects.id', 'subjects.name', 'subjects.code')
            ->selectRaw('subjects.id, subjects.name, subjects.code, MAX(exam_result_items.max_marks) AS max_marks, COUNT(*) AS total, '.$this->statusSums('exam_result_items.status'))
            ->orderBy('subjects.name')
            ->orderBy('subjects.id')
            ->paginate(15, ['*'], 'subject_page')
            ->withQueryString()
            ->through(fn ($row) => $this->withPassRate($row));
    }

    /**
     * Published results narrowed by the report filters.
     */
    private function filteredResults(?int $examinationId, ?int $programId): \Illuminate\Database\Eloquent\Builder
    {
        $query = ExamResult::query()->whereNotNull('published_at');

        if ($examinationId !== null) {
            $query->where('examination_id', $examinationId);
        }

        if ($programId !== null) {
            $query->whereHas('studentEnrollment', fn ($enrollment) => $enrollment->where('program_id', $programId));
        }

        return $query;
    }

    /**
     * Portable per-status COUNT expressions sharing the one controlled
     * result-status vocabulary (the item vocabulary is identical by design).
     */
    private function statusSums(string $column): string
    {
        $parts = [];
        foreach (ExamResult::RESULT_STATUSES as $status) {
            $parts[] = "SUM(CASE WHEN {$column} = '".$status."' THEN 1 ELSE 0 END) AS {$status}_count";
        }

        return implode(', ', $parts);
    }

    private function withPassRate(object $row): object
    {
        $total = (int) $row->total;
        $row->pass_rate = $total > 0 ? round(((int) $row->pass_count) * 100 / $total, 1) : null;

        // Raw aggregates come back driver-typed; normalize for display so the
        // view prints values exactly like the stored decimal columns elsewhere.
        if (property_exists($row, 'max_marks')) {
            $row->max_marks = $row->max_marks !== null ? number_format((float) $row->max_marks, 2) : null;
        }

        return $row;
    }
}
