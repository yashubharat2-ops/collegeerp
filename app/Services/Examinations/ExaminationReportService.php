<?php

namespace App\Services\Examinations;

use App\Models\Examination;
use App\Models\ExamAttendance;
use App\Models\ExamMark;
use App\Models\ExamResult;
use App\Models\ExamResultItem;
use App\Models\ExamSchedule;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Paginator;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

/**
 * ExaminationReportService — the read side of the Examination Reports module.
 *
 * Live, read-only reporting over the EXISTING Examinations tables: Examination,
 * ExamSchedule, ExamAttendance, ExamMark, ExamResult / ExamResultItem (and the
 * Marksheet / Grade Card / Student Result History views that project them).
 * Nothing is persisted: no report tables, no snapshots, no duplicated
 * calculation or grading logic — totals, rates and ranks are simple aggregate
 * queries over stored rows, and pass/grade values are read as stored.
 *
 * Rules honoured by every method:
 *
 *  - Tenant safety — root queries go through models carrying CollegeScope, and
 *    every JOIN gets an explicit same-college + soft-delete guard (global
 *    scopes do not apply to joined tables), so a forged foreign filter id can
 *    only ever produce an empty report — never cross-tenant rows.
 *  - Published-only where the rest of the system is published-only — subject
 *    results, student results, pass/fail, grades, merit, marksheets, grade
 *    cards and result history only ever read published results, exactly like
 *    Marksheets, Grade Cards and Student Result History do. The Result
 *    Publishing report reads aggregate publication counts (never unpublished
 *    student rows).
 *  - Deterministic pagination — every ordered query ends with a unique
 *    tiebreak (usually the id) so pages never overlap or skip rows.
 *  - Constant query counts — counts are aggregated in SQL, labels are
 *    eager-loaded; no per-row queries are issued as data grows.
 */
class ExaminationReportService
{
    public const PER_PAGE = 20;

    /* ------------------------------------------------------------------ *
     * 1. Examination Summary
     * ------------------------------------------------------------------ */

    /**
     * One row per examination with live counts of its schedules, marks,
     * attendance records and (published) results, all as correlated
     * subqueries — constant query count regardless of rows.
     */
    public function summary(array $filters): array
    {
        $base = Examination::query();
        $this->examinationFilters($base, $filters, true);

        $schedules = fn (): Builder => ExamSchedule::query()
            ->whereColumn('exam_schedules.examination_id', 'examinations.id');
        $scheduleRows = fn (): Builder => ExamSchedule::query()
            ->select('exam_schedules.id')
            ->whereColumn('exam_schedules.examination_id', 'examinations.id');
        $results = fn (): Builder => ExamResult::query()
            ->whereColumn('exam_results.examination_id', 'examinations.id');

        $rows = (clone $base)->select('examinations.*')
            ->with(['academicYear:id,name,code', 'academicTerm:id,name,code'])
            ->addSelect([
            'schedules_count' => $schedules()->selectRaw('COUNT(*)'),
            'subjects_count' => $schedules()->selectRaw('COUNT(DISTINCT exam_schedules.subject_id)'),
            'attendance_count' => ExamAttendance::query()->selectRaw('COUNT(*)')
                ->whereIn('exam_attendances.exam_schedule_id', $scheduleRows()),
            'marks_count' => ExamMark::query()->selectRaw('COUNT(*)')
                ->whereIn('exam_marks.exam_schedule_id', $scheduleRows()),
            'results_count' => $results()->selectRaw('COUNT(*)'),
            'published_count' => $results()->whereNotNull('published_at')->selectRaw('COUNT(*)'),
            'pass_count' => $results()->whereNotNull('published_at')
                ->where('exam_results.result_status', ExamResult::RESULT_PASS)->selectRaw('COUNT(*)'),
        ])
            ->orderByDesc('examinations.start_date')
            ->orderByDesc('examinations.id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return ['rows' => $rows];
    }

    /* ------------------------------------------------------------------ *
     * 2. Exam Schedule Report
     * ------------------------------------------------------------------ */

    public function schedule(array $filters): array
    {
        $base = ExamSchedule::query();
        $this->scheduleFilters($base, $filters);

        $rows = (clone $base)->with([
            'examination:id,name,code', 'academicYear:id,name,code', 'academicTerm:id,name,code',
            'program:id,name,code,department_id', 'program.department:id,name',
            'section:id,name,code', 'subject:id,name,code',
            'faculty:id,employee_code,first_name,middle_name,last_name',
        ])
            ->orderBy('exam_schedules.exam_date')
            ->orderBy('exam_schedules.start_time')
            ->orderBy('exam_schedules.id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return [
            'rows' => $rows,
            'subjectsCount' => (clone $base)->distinct()->count('exam_schedules.subject_id'),
            'facultyCount' => (clone $base)->whereNotNull('faculty_id')->distinct()->count('exam_schedules.faculty_id'),
        ];
    }

    /* ------------------------------------------------------------------ *
     * 3. Exam Attendance Report
     * ------------------------------------------------------------------ */

    public function attendance(array $filters): array
    {
        $base = $this->attendanceQuery($filters);
        if ($filters['attendance_status']) {
            $base->where('exam_attendances.attendance_status', $filters['attendance_status']);
        }

        $counts = (clone $base)->selectRaw('exam_attendances.attendance_status, COUNT(*) AS total')
            ->groupBy('exam_attendances.attendance_status')->pluck('total', 'attendance_status');

        $rows = (clone $base)->with([
            'examSchedule:id,examination_id,subject_id,program_id,section_id,exam_date',
            'examSchedule.examination:id,name,code', 'examSchedule.subject:id,name,code',
            'studentEnrollment:id,student_id,enrollment_number',
            'studentEnrollment.student:id,student_number,first_name,middle_name,last_name',
            'studentEnrollment.program:id,name,code', 'studentEnrollment.section:id,name,code',
        ])
            ->orderByDesc($this->scheduleDate('exam_attendances.exam_schedule_id'))
            ->orderByDesc('exam_attendances.id')
            ->paginate(self::PER_PAGE)->withQueryString();

        $total = array_sum($counts->all());
        $attended = (int) ($counts[ExamAttendance::STATUS_PRESENT] ?? 0)
            + (int) ($counts[ExamAttendance::STATUS_LATE] ?? 0);

        return [
            'rows' => $rows,
            'counts' => collect(ExamAttendance::STATUSES)
                ->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)])->all(),
            'attendanceRate' => $total > 0 ? round($attended * 100 / $total, 1) : null,
        ];
    }

    /* ------------------------------------------------------------------ *
     * 4. Marks / Marks Entry Report
     * ------------------------------------------------------------------ */

    public function marks(array $filters): array
    {
        $base = $this->markQuery($filters);
        if ($filters['mark_status']) {
            $base->where('exam_marks.status', $filters['mark_status']);
        }

        $rows = (clone $base)->with([
            'examSchedule:id,examination_id,subject_id,program_id,section_id,exam_date',
            'examSchedule.examination:id,name,code', 'examSchedule.subject:id,name,code',
            'studentEnrollment:id,student_id,enrollment_number',
            'studentEnrollment.student:id,student_number,first_name,middle_name,last_name',
            'studentEnrollment.program:id,name,code', 'studentEnrollment.section:id,name,code',
        ])
            ->orderByDesc($this->scheduleDate('exam_marks.exam_schedule_id'))
            ->orderByDesc('exam_marks.id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return [
            'rows' => $rows,
            'scoredCount' => (clone $base)->whereNotNull('exam_marks.obtained_marks')->count(),
            'averageMarks' => $this->rounded((clone $base)->avg('exam_marks.obtained_marks'), 2),
        ];
    }

    /* ------------------------------------------------------------------ *
     * 5. Subject-wise Result Report (published results only)
     * ------------------------------------------------------------------ */

    public function subjectResults(array $filters): array
    {
        $base = $this->resultItemQuery($filters);

        $statusSum = fn (string $status): string => "SUM(CASE WHEN exam_result_items.status = '{$status}' THEN 1 ELSE 0 END)";

        $rows = (clone $base)->select('subjects.id', 'subjects.name', 'subjects.code')
            ->addSelect([
                'items_count' => DB::raw('COUNT(*)'),
                'appeared_count' => DB::raw('COUNT(exam_result_items.obtained_marks)'),
                'avg_marks' => DB::raw('ROUND(AVG(exam_result_items.obtained_marks), 2)'),
                'max_marks' => DB::raw('MAX(exam_result_items.max_marks)'),
                'pass_count' => DB::raw($statusSum(ExamResult::RESULT_PASS)),
                'fail_count' => DB::raw($statusSum(ExamResult::RESULT_FAIL)),
                'absent_count' => DB::raw($statusSum(ExamResult::RESULT_ABSENT)),
                'withheld_count' => DB::raw($statusSum(ExamResult::RESULT_WITHHELD)),
                'incomplete_count' => DB::raw($statusSum(ExamResult::RESULT_INCOMPLETE)),
            ])
            ->groupBy('subjects.id', 'subjects.name', 'subjects.code')
            ->orderBy('subjects.name')->orderBy('subjects.id')
            ->paginate(self::PER_PAGE)->withQueryString();

        $overall = (clone $base)->selectRaw(
            'COUNT(*) AS items_count, COUNT(exam_result_items.obtained_marks) AS appeared_count, '.
            $statusSum(ExamResult::RESULT_PASS).' AS pass_count'
        )->first();

        return [
            'rows' => $rows,
            'subjectsCount' => $rows->total(),
            'itemsCount' => (int) ($overall->items_count ?? 0),
            'passCount' => (int) ($overall->pass_count ?? 0),
        ];
    }

    /* ------------------------------------------------------------------ *
     * 6. Student Result Report (published results only)
     * ------------------------------------------------------------------ */

    public function studentResults(array $filters): array
    {
        $base = $this->resultQuery($filters);

        $counts = (clone $base)->selectRaw('exam_results.result_status, COUNT(*) AS total')
            ->groupBy('exam_results.result_status')->pluck('total', 'result_status');

        $rows = (clone $base)->with([
            'examination:id,name,code', 'academicYear:id,name,code', 'academicTerm:id,name,code',
            'studentEnrollment:id,student_id,enrollment_number',
            'studentEnrollment.student:id,student_number,first_name,middle_name,last_name',
            'studentEnrollment.program:id,name,code', 'studentEnrollment.section:id,name,code',
        ])
            ->orderByDesc('exam_results.published_at')
            ->orderByDesc('exam_results.id')
            ->paginate(self::PER_PAGE)->withQueryString();

        $total = array_sum($counts->all());

        return [
            'rows' => $rows,
            'counts' => collect(ExamResult::RESULT_STATUSES)
                ->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)])->all(),
            'passRate' => $total > 0
                ? round(((int) ($counts[ExamResult::RESULT_PASS] ?? 0)) * 100 / $total, 1)
                : null,
        ];
    }

    /* ------------------------------------------------------------------ *
     * 7. Pass / Fail Report (published results only, grouped by program)
     * ------------------------------------------------------------------ */

    public function passFail(array $filters): array
    {
        $base = $this->publishedResultWithEnrollment($filters);

        $statusSum = fn (string $status): string => "SUM(CASE WHEN exam_results.result_status = '{$status}' THEN 1 ELSE 0 END)";

        $rows = (clone $base)->select('programs.id', 'programs.name', 'programs.code')
            ->addSelect([
                'total' => DB::raw('COUNT(*)'),
                'pass_count' => DB::raw($statusSum(ExamResult::RESULT_PASS)),
                'fail_count' => DB::raw($statusSum(ExamResult::RESULT_FAIL)),
                'absent_count' => DB::raw($statusSum(ExamResult::RESULT_ABSENT)),
                'withheld_count' => DB::raw($statusSum(ExamResult::RESULT_WITHHELD)),
                'incomplete_count' => DB::raw($statusSum(ExamResult::RESULT_INCOMPLETE)),
            ])
            ->groupBy('programs.id', 'programs.name', 'programs.code')
            ->orderBy('programs.name')->orderBy('programs.id')
            ->paginate(15)->withQueryString()
            ->through(fn (object $row): object => $this->withRate($row));

        $counts = (clone $base)->selectRaw('exam_results.result_status, COUNT(*) AS total')
            ->groupBy('exam_results.result_status')->pluck('total', 'result_status');
        $total = array_sum($counts->all());

        return [
            'rows' => $rows,
            'totalResults' => $total,
            'passCount' => (int) ($counts[ExamResult::RESULT_PASS] ?? 0),
            'failCount' => (int) ($counts[ExamResult::RESULT_FAIL] ?? 0),
            'passRate' => $total > 0
                ? round(((int) ($counts[ExamResult::RESULT_PASS] ?? 0)) * 100 / $total, 1)
                : null,
        ];
    }

    /* ------------------------------------------------------------------ *
     * 8. Grade-wise Report (published results only, grouped by grade)
     * ------------------------------------------------------------------ */

    public function grades(array $filters): array
    {
        $base = $this->resultQuery($filters);

        $rows = (clone $base)->selectRaw('exam_results.overall_grade, COUNT(*) AS total, AVG(exam_results.percentage) AS avg_percentage')
            ->addSelect([
                'pass_count' => DB::raw("SUM(CASE WHEN exam_results.result_status = '".ExamResult::RESULT_PASS."' THEN 1 ELSE 0 END)"),
                'fail_count' => DB::raw("SUM(CASE WHEN exam_results.result_status = '".ExamResult::RESULT_FAIL."' THEN 1 ELSE 0 END)"),
            ])
            ->groupBy('exam_results.overall_grade')
            ->orderByRaw('AVG(exam_results.percentage) DESC')
            ->orderBy('exam_results.overall_grade')
            ->orderByRaw('MIN(exam_results.id)')
            ->paginate(self::PER_PAGE)->withQueryString();

        $total = (clone $base)->count();
        $graded = (clone $base)->whereNotNull('overall_grade')->count();

        return [
            'rows' => $rows,
            'totalResults' => $total,
            'gradedCount' => $graded,
        ];
    }

    /* ------------------------------------------------------------------ *
     * 9. Merit / Rank Report (published results only, ranked)
     * ------------------------------------------------------------------ */

    /**
     * Ranks are computed over the WHOLE filtered set (not just the page),
     * ordered by percentage, then total obtained marks, then id — so the
     * ranking is deterministic and pagination can never renumber rows.
     */
    public function merit(array $filters): array
    {
        $base = $this->resultQuery($filters);

        // One light query yields the full deterministic order; ranks are
        // assigned in PHP and the page rows are then eager-loaded by id.
        $ordered = (clone $base)
            ->orderByDesc('exam_results.percentage')
            ->orderByDesc('exam_results.total_obtained_marks')
            ->orderBy('exam_results.id')
            ->get(['exam_results.id', 'exam_results.percentage', 'exam_results.total_obtained_marks', 'exam_results.result_status']);

        $ranks = [];
        foreach ($ordered as $position => $result) {
            $ranks[$result->id] = $position + 1;
        }

        $page = LengthAwarePaginator::resolveCurrentPage('page');
        $pageIds = array_keys(array_slice($ranks, ($page - 1) * self::PER_PAGE, self::PER_PAGE, true));

        $byId = (clone $base)->whereIn('exam_results.id', $pageIds)->with([
            'examination:id,name,code',
            'studentEnrollment:id,student_id,enrollment_number',
            'studentEnrollment.student:id,student_number,first_name,middle_name,last_name',
            'studentEnrollment.program:id,name,code', 'studentEnrollment.section:id,name,code',
        ])->get()->keyBy('id');

        $rows = collect($pageIds)
            ->map(fn (int $id) => $byId->get($id))
            ->filter()
            ->each(function (ExamResult $result) use ($ranks): void {
                $result->rank = $ranks[$result->id];
            })
            ->values();

        $passCount = $ordered->where('result_status', ExamResult::RESULT_PASS)->count();

        return [
            'rows' => new LengthAwarePaginator($rows, count($ranks), self::PER_PAGE, $page, [
                'path' => Paginator::resolveCurrentPath(),
                'query' => request()->query(),
            ]),
            'candidatesCount' => count($ranks),
            'passCount' => $passCount,
            'topPercentage' => $ordered->max('percentage'),
        ];
    }

    /* ------------------------------------------------------------------ *
     * 10. Result Publishing Report
     * ------------------------------------------------------------------ */

    /**
     * Per-examination publication progress (aggregate counts only — the
     * unpublished figures are totals, never unpublished student rows).
     */
    public function publishing(array $filters): array
    {
        $base = Examination::query();
        $this->examinationFilters($base, $filters, true);

        $results = fn (): Builder => ExamResult::query()
            ->whereColumn('exam_results.examination_id', 'examinations.id');

        $rows = (clone $base)->select('examinations.*')
            ->with(['academicYear:id,name,code', 'academicTerm:id,name,code'])
            ->addSelect([
            'results_count' => $results()->selectRaw('COUNT(*)'),
            'calculated_count' => $results()->where('exam_results.calculation_status', ExamResult::CALCULATION_CALCULATED)
                ->selectRaw('COUNT(*)'),
            'published_count' => $results()->whereNotNull('published_at')->selectRaw('COUNT(*)'),
            'last_published_at' => $results()->selectRaw('MAX(exam_results.published_at)'),
        ])
            ->orderByDesc('examinations.start_date')
            ->orderByDesc('examinations.id')
            ->paginate(self::PER_PAGE)->withQueryString()
            ->through(function (object $row): object {
                $row->publish_rate = (int) $row->results_count > 0
                    ? round((int) $row->published_count * 100 / (int) $row->results_count, 1)
                    : null;

                return $row;
            });

        $all = fn (): \Illuminate\Database\Eloquent\Builder => ExamResult::query()
            ->whereIn('examination_id', (clone $base)->select('examinations.id'));
        $resultsCount = $all()->count();
        $publishedCount = $all()->whereNotNull('published_at')->count();

        return [
            'rows' => $rows,
            'resultsCount' => $resultsCount,
            'publishedCount' => $publishedCount,
            'unpublishedCount' => $resultsCount - $publishedCount,
        ];
    }

    /* ------------------------------------------------------------------ *
     * 11. Marksheet Report (published results only)
     * ------------------------------------------------------------------ */

    public function marksheet(array $filters): array
    {
        return $this->documentReport($filters, 'marksheet');
    }

    /* ------------------------------------------------------------------ *
     * 12. Grade Card Report (published results only)
     * ------------------------------------------------------------------ */

    public function gradeCard(array $filters): array
    {
        return $this->documentReport($filters, 'grade_card');
    }

    /** Shared read path for the two printable-document reports. */
    private function documentReport(array $filters, string $kind): array
    {
        $base = $this->resultQuery($filters);
        if ($filters['from']) {
            $base->where('exam_results.published_at', '>=', $filters['from']);
        }
        if ($filters['to']) {
            // Half-open upper bound covers the whole final day.
            $base->where('exam_results.published_at', '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateString());
        }

        $counts = (clone $base)->selectRaw('exam_results.result_status, COUNT(*) AS total')
            ->groupBy('exam_results.result_status')->pluck('total', 'result_status');

        $rows = (clone $base)->select('exam_results.*')->addSelect([
            'subjects_count' => ExamResultItem::query()->selectRaw('COUNT(*)')
                ->whereColumn('exam_result_items.exam_result_id', 'exam_results.id'),
        ])->with([
            'examination:id,name,code', 'academicYear:id,name,code', 'academicTerm:id,name,code',
            'studentEnrollment:id,student_id,enrollment_number',
            'studentEnrollment.student:id,student_number,first_name,middle_name,last_name',
            'studentEnrollment.program:id,name,code', 'studentEnrollment.section:id,name,code',
            // Grade cards render the stored grade point of the result's own
            // grade scale — a lookup, never a new calculation.
            'gradeScale.items',
        ])
            ->orderByDesc('exam_results.published_at')
            ->orderByDesc('exam_results.id')
            ->paginate(self::PER_PAGE)->withQueryString();

        $total = array_sum($counts->all());

        return [
            'rows' => $rows,
            'kind' => $kind,
            'totalResults' => $total,
            'passCount' => (int) ($counts[ExamResult::RESULT_PASS] ?? 0),
            'passRate' => $total > 0
                ? round(((int) ($counts[ExamResult::RESULT_PASS] ?? 0)) * 100 / $total, 1)
                : null,
        ];
    }

    /* ------------------------------------------------------------------ *
     * 13. Student Result History (published results only, grouped by student)
     * ------------------------------------------------------------------ */

    public function resultHistory(array $filters): array
    {
        $base = $this->historyQuery($filters);

        $rows = (clone $base)->selectRaw(
            'student_enrollments.student_id, COUNT(*) AS results_count, '.
            'MAX(exam_results.published_at) AS last_published_at, '.
            'MAX(exam_results.percentage) AS best_percentage'
        )
            ->groupBy('student_enrollments.student_id')
            ->orderByRaw('MAX(exam_results.published_at) DESC')
            ->orderBy('student_enrollments.student_id')
            ->paginate(self::PER_PAGE)->withQueryString();

        $pageIds = $rows->getCollection()->pluck('student_id')->filter()->unique()->values()->all();
        $students = $pageIds === [] ? collect() : Student::query()->whereIn('id', $pageIds)
            ->get(['id', 'student_number', 'first_name', 'middle_name', 'last_name', 'status'])->keyBy('id');

        // One query resolves the latest published examination per page student.
        $latest = $pageIds === [] ? collect() : (clone $base)
            ->select([
                'exam_results.id', 'exam_results.student_enrollment_id', 'exam_results.examination_id',
                'exam_results.published_at', 'exam_results.overall_grade', 'exam_results.result_status',
            ])
            ->addSelect('student_enrollments.student_id AS report_student_id')
            ->with('examination:id,name,code')
            ->orderByDesc('exam_results.published_at')
            ->orderByDesc('exam_results.id')
            ->get()
            ->groupBy('report_student_id')
            ->mapWithKeys(fn (Collection $group, $studentId): array => [(int) $studentId => $group]);

        $rows->getCollection()->each(function (object $row) use ($students, $latest): void {
            $row->student = $students->get((int) $row->student_id);
            $row->latest = $latest->get((int) $row->student_id)?->first();
        });

        $resultsCount = (clone $base)->count();
        $passResults = (clone $base)->where('exam_results.result_status', ExamResult::RESULT_PASS)->count();

        return [
            'rows' => $rows,
            'resultsCount' => $resultsCount,
            'passCount' => $passResults,
        ];
    }

    /* ------------------------------------------------------------------ *
     * Shared query builders
     * ------------------------------------------------------------------ */

    /** Examination-level filters (summary + publishing). */
    private function examinationFilters(Builder $query, array $filters, bool $dates): Builder
    {
        if ($filters['academic_year_id']) {
            $query->where('examinations.academic_year_id', $filters['academic_year_id']);
        }
        if ($filters['academic_term_id']) {
            $query->where('examinations.academic_term_id', $filters['academic_term_id']);
        }
        if ($filters['examination_id']) {
            $query->where('examinations.id', $filters['examination_id']);
        }
        if ($filters['status']) {
            $query->where('examinations.status', $filters['status']);
        }
        if ($filters['search'] !== '') {
            $like = '%'.$filters['search'].'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('examinations.name', 'like', $like)->orWhere('examinations.code', 'like', $like);
            });
        }
        if ($dates) {
            // Overlap with [from, to]; examinations without dates are hidden
            // once a date window is chosen.
            if ($filters['from']) {
                $query->whereNotNull('examinations.start_date')
                    ->where(fn (Builder $q) => $q->where('examinations.end_date', '>=', $filters['from'])
                        ->orWhereNull('examinations.end_date'));
            }
            if ($filters['to']) {
                $query->whereNotNull('examinations.start_date')
                    ->where('examinations.start_date', '<=', $filters['to']);
            }
        }

        return $query;
    }

    /** ExamSchedule-level filters (schedule report + schedule-scoped lists). */
    private function scheduleFilters(Builder $query, array $filters): Builder
    {
        if ($filters['academic_year_id']) {
            $query->where('exam_schedules.academic_year_id', $filters['academic_year_id']);
        }
        if ($filters['academic_term_id']) {
            $query->where('exam_schedules.academic_term_id', $filters['academic_term_id']);
        }
        foreach (['examination_id', 'program_id', 'section_id', 'subject_id', 'faculty_id'] as $field) {
            if ($filters[$field]) {
                $query->where('exam_schedules.'.$field, $filters[$field]);
            }
        }
        if ($filters['department_id']) {
            $query->whereHas('program', fn (Builder $program) => $program->where('programs.department_id', $filters['department_id']));
        }
        if ($filters['status']) {
            $query->where('exam_schedules.status', $filters['status']);
        }
        $this->dateRange($query, $filters, 'exam_schedules.exam_date');

        return $query;
    }

    private function attendanceQuery(array $filters): Builder
    {
        return ExamAttendance::query()->whereHas('examSchedule', function (Builder $schedule) use ($filters): void {
            $this->scheduleFilters($schedule, $filters);
        })->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
            $this->studentSearch($query, $filters['search'], 'studentEnrollment.student');
        });
    }

    private function markQuery(array $filters): Builder
    {
        return ExamMark::query()->whereHas('examSchedule', function (Builder $schedule) use ($filters): void {
            $this->scheduleFilters($schedule, $filters);
        })->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
            $this->studentSearch($query, $filters['search'], 'studentEnrollment.student');
        });
    }

    /**
     * Published ExamResults with the existing read-path filters
     * (year / term / examination / program / section / department / student).
     */
    private function resultQuery(array $filters): Builder
    {
        $query = ExamResult::query()->whereNotNull('exam_results.published_at');

        if ($filters['academic_year_id']) {
            $query->where('exam_results.academic_year_id', $filters['academic_year_id']);
        }
        if ($filters['academic_term_id']) {
            $query->where('exam_results.academic_term_id', $filters['academic_term_id']);
        }
        if ($filters['examination_id']) {
            $query->where('exam_results.examination_id', $filters['examination_id']);
        }
        if ($filters['result_status']) {
            $query->where('exam_results.result_status', $filters['result_status']);
        }
        if ($filters['grade']) {
            $query->where('exam_results.overall_grade', $filters['grade']);
        }
        if ($filters['program_id'] || $filters['section_id'] || $filters['department_id'] || $filters['search'] !== '') {
            $query->whereHas('studentEnrollment', function (Builder $enrollment) use ($filters): void {
                if ($filters['program_id']) {
                    $enrollment->where('student_enrollments.program_id', $filters['program_id']);
                }
                if ($filters['section_id']) {
                    $enrollment->where('student_enrollments.section_id', $filters['section_id']);
                }
                if ($filters['department_id']) {
                    $enrollment->whereHas('program', fn (Builder $program) => $program->where('programs.department_id', $filters['department_id']));
                }
                if ($filters['search'] !== '') {
                    $this->studentSearch($enrollment, $filters['search']);
                }
            });
        }

        return $query;
    }

    /** Published results joined to their (guarded) enrollment + program. */
    private function publishedResultWithEnrollment(array $filters): Builder
    {
        return $this->resultQuery($filters)
            ->join('student_enrollments', function (JoinClause $join): void {
                $join->on('student_enrollments.id', '=', 'exam_results.student_enrollment_id')
                    ->whereColumn('student_enrollments.college_id', 'exam_results.college_id')
                    ->whereNull('student_enrollments.deleted_at');
            })
            ->join('programs', function (JoinClause $join): void {
                $join->on('programs.id', '=', 'student_enrollments.program_id')
                    ->whereColumn('programs.college_id', 'student_enrollments.college_id')
                    ->whereNull('programs.deleted_at');
            })
            ->when($filters['program_id'], fn (Builder $q) => $q->where('student_enrollments.program_id', $filters['program_id']))
            ->when($filters['section_id'], fn (Builder $q) => $q->where('student_enrollments.section_id', $filters['section_id']))
            ->when($filters['department_id'], fn (Builder $q) => $q->where('programs.department_id', $filters['department_id']));
    }

    /**
     * Published ExamResultItems grouped by subject. ExamResultItem carries
     * CollegeScope; every joined table gets a same-college + soft-delete
     * guard because global scopes do not apply to joins.
     */
    private function resultItemQuery(array $filters): Builder
    {
        $query = ExamResultItem::query()
            ->join('exam_results', function (JoinClause $join): void {
                $join->on('exam_results.id', '=', 'exam_result_items.exam_result_id')
                    ->whereColumn('exam_results.college_id', 'exam_result_items.college_id')
                    ->whereNull('exam_results.deleted_at');
            })
            ->whereNotNull('exam_results.published_at')
            ->join('subjects', function (JoinClause $join): void {
                $join->on('subjects.id', '=', 'exam_result_items.subject_id')
                    ->whereColumn('subjects.college_id', 'exam_result_items.college_id')
                    ->whereNull('subjects.deleted_at');
            });

        if ($filters['academic_year_id']) {
            $query->where('exam_results.academic_year_id', $filters['academic_year_id']);
        }
        if ($filters['academic_term_id']) {
            $query->where('exam_results.academic_term_id', $filters['academic_term_id']);
        }
        if ($filters['examination_id']) {
            $query->where('exam_results.examination_id', $filters['examination_id']);
        }
        if ($filters['subject_id']) {
            $query->where('exam_result_items.subject_id', $filters['subject_id']);
        }
        if ($filters['result_status']) {
            $query->where('exam_result_items.status', $filters['result_status']);
        }

        if ($filters['program_id'] || $filters['section_id'] || $filters['department_id'] || $filters['faculty_id']) {
            $query->join('student_enrollments', function (JoinClause $join): void {
                $join->on('student_enrollments.id', '=', 'exam_results.student_enrollment_id')
                    ->whereColumn('student_enrollments.college_id', 'exam_results.college_id')
                    ->whereNull('student_enrollments.deleted_at');
            });
            if ($filters['program_id']) {
                $query->where('student_enrollments.program_id', $filters['program_id']);
            }
            if ($filters['section_id']) {
                $query->where('student_enrollments.section_id', $filters['section_id']);
            }
            if ($filters['department_id']) {
                $query->join('programs', function (JoinClause $join): void {
                    $join->on('programs.id', '=', 'student_enrollments.program_id')
                        ->whereColumn('programs.college_id', 'student_enrollments.college_id')
                        ->whereNull('programs.deleted_at');
                })->where('programs.department_id', $filters['department_id']);
            }
        }

        if ($filters['faculty_id']) {
            $query->join('exam_schedules', function (JoinClause $join): void {
                $join->on('exam_schedules.id', '=', 'exam_result_items.exam_schedule_id')
                    ->whereColumn('exam_schedules.college_id', 'exam_result_items.college_id')
                    ->whereNull('exam_schedules.deleted_at');
            })->where('exam_schedules.faculty_id', $filters['faculty_id']);
        }

        return $query;
    }

    /**
     * Published results joined to a same-college enrollment — the base for
     * the Student Result History aggregates. All filters apply to both the
     * aggregate rows and the "latest published" lookup.
     */
    private function historyQuery(array $filters): Builder
    {
        return $this->resultQuery($filters)
            ->join('student_enrollments', function (JoinClause $join): void {
                $join->on('student_enrollments.id', '=', 'exam_results.student_enrollment_id')
                    ->whereColumn('student_enrollments.college_id', 'exam_results.college_id')
                    ->whereNull('student_enrollments.deleted_at');
            })
            ->when($filters['program_id'], fn (Builder $q) => $q->where('student_enrollments.program_id', $filters['program_id']))
            ->when($filters['section_id'], fn (Builder $q) => $q->where('student_enrollments.section_id', $filters['section_id']));
    }

    /** Correlated exam-date ordering for attendance / marks rows. */
    private function scheduleDate(string $scheduleIdColumn): Builder
    {
        return ExamSchedule::query()
            ->select('exam_schedules.exam_date')
            ->whereColumn('exam_schedules.id', $scheduleIdColumn);
    }

    private function studentSearch(Builder $query, string $search, string $relation = 'student'): Builder
    {
        $like = '%'.$search.'%';

        return $query->whereHas($relation, fn (Builder $student) => $student->where(function (Builder $s) use ($like): void {
            $s->where('students.student_number', 'like', $like)
                ->orWhere('students.first_name', 'like', $like)
                ->orWhere('students.middle_name', 'like', $like)
                ->orWhere('students.last_name', 'like', $like);
        }));
    }

    private function dateRange(Builder $query, array $filters, string $column): Builder
    {
        if ($filters['from']) {
            $query->where($column, '>=', $filters['from']);
        }
        if ($filters['to']) {
            // Half-open upper bound covers the whole final day even when the
            // date cast stores a midnight time suffix (as on SQLite).
            $query->where($column, '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateString());
        }

        return $query;
    }

    private function withRate(object $row): object
    {
        $total = (int) $row->total;
        $row->pass_rate = $total > 0 ? round(((int) $row->pass_count) * 100 / $total, 1) : null;

        return $row;
    }

    private function rounded(float|string|null $value, int $precision): ?float
    {
        return $value === null ? null : round((float) $value, $precision);
    }
}
