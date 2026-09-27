<?php

namespace App\Domain\Student\Services;

use App\Models\Admission;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentPromotion;
use App\Models\StudentTransfer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Live, read-only Student Reports. The owning modules remain the only sources
 * of facts; in particular, no report rows or cached student classifications are
 * persisted. All root models and every whereHas/eager-loaded relationship carry
 * CollegeScope. The one raw subquery below explicitly correlates on college_id.
 */
class StudentReportService
{
    private const PER_PAGE = 20;

    /** Student list, profile picker and history picker (one row per student). */
    public function roster(array $filters, bool $withEnrollment = false): LengthAwarePaginator
    {
        $query = $this->studentQuery($filters);
        $this->dateRange($query, $filters, 'students.admission_date');

        if ($withEnrollment) {
            // The enrollment displayed must satisfy the SAME filters as the
            // whereHas on the student. Without a context filter, use the
            // canonical Student::currentEnrollment() on these eager-loaded rows.
            $query->with([
                'enrollments' => function (HasMany $enrollments) use ($filters): void {
                    $query = $enrollments->getQuery();
                    if ($this->hasEnrollmentFilters($filters)) {
                        $this->enrollmentContext($query, $filters);
                    }
                    $query->orderBy('created_at')->orderBy('id');
                },
                'enrollments.academicYear',
                'enrollments.program.department',
                'enrollments.section',
            ]);
        }

        return $query->orderByDesc('students.created_at')->orderByDesc('students.id')
            ->paginate(self::PER_PAGE)->withQueryString();
    }

    /** Official admissions, including those not yet converted to students. */
    public function admissions(array $filters): LengthAwarePaginator
    {
        $query = Admission::query()->with([
            'applicant', 'application.student', 'academicYear', 'program.department',
        ]);

        if ($filters['academic_year_id']) {
            $query->whereHas('academicYear', fn (Builder $year) => $year->whereKey($filters['academic_year_id']));
        }
        if ($filters['program_id'] || $filters['department_id']) {
            $query->whereHas('program', function (Builder $program) use ($filters): void {
                $this->programContext($program, $filters);
            });
        }
        if ($filters['student_status'] && $filters['student_status'] !== 'all') {
            $query->whereHas('application.student', fn (Builder $student) => $student->where('status', $filters['student_status']));
        }
        if ($filters['admission_status']) {
            $query->where('status', $filters['admission_status']);
        }
        if ($filters['search'] !== '') {
            $like = '%'.$filters['search'].'%';
            $query->where(function (Builder $admission) use ($like): void {
                $admission->where('admission_number', 'like', $like)
                    ->orWhereHas('applicant', function (Builder $applicant) use ($like): void {
                        $applicant->where(fn (Builder $name) => $name->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
                    })
                    ->orWhereHas('application.student', fn (Builder $student) => $this->searchStudent($student, $like));
            });
        }
        $this->dateRange($query, $filters, 'admissions.admission_date');

        return $query->orderByDesc('admissions.admission_date')->orderByDesc('admissions.id')
            ->paginate(self::PER_PAGE)->withQueryString();
    }

    public function enrollments(array $filters): LengthAwarePaginator
    {
        return $this->enrollmentQuery($filters)
            ->with(['student', 'academicYear', 'program.department', 'section'])
            ->orderByDesc('student_enrollments.enrollment_date')->orderByDesc('student_enrollments.id')
            ->paginate(self::PER_PAGE)->withQueryString();
    }

    /** Distinct students per year / program / section; totals are not sums of groups. */
    public function strength(array $filters): array
    {
        $query = $this->enrollmentQuery($filters);

        return [
            'rows' => (clone $query)
                ->selectRaw('academic_year_id, program_id, section_id, COUNT(DISTINCT student_enrollments.student_id) AS students_count')
                ->groupBy('academic_year_id', 'program_id', 'section_id')
                ->with(['academicYear:id,name,code', 'program:id,name,code,department_id', 'program.department:id,name', 'section:id,name,code'])
                ->orderBy('academic_year_id')->orderBy('program_id')->orderBy('section_id')
                ->paginate(self::PER_PAGE)->withQueryString(),
            'studentsCount' => (clone $query)->distinct()->count('student_enrollments.student_id'),
            'enrollmentsCount' => (clone $query)->count(),
        ];
    }

    /**
     * New = enrolled in the student's FIRST academic year (by earliest live
     * enrollment_date, id tiebreak); old = enrolled in another year. All rows
     * in that first year are new, even if the student changed program/section.
     * Soft-deleted enrollments are excluded, just as in the other reports.
     */
    public function newOld(array $filters): array
    {
        $base = $this->enrollmentQuery($filters);
        $counts = [
            'new' => (clone $base)->where('student_enrollments.academic_year_id', '=', $this->firstAcademicYear())
                ->distinct()->count('student_enrollments.student_id'),
            'old' => (clone $base)->where('student_enrollments.academic_year_id', '!=', $this->firstAcademicYear())
                ->distinct()->count('student_enrollments.student_id'),
        ];

        $rows = (clone $base)->select('student_enrollments.*')
            ->selectSub($this->firstAcademicYear(), 'first_academic_year_id')
            ->with(['student', 'academicYear', 'program.department', 'section']);

        if ($filters['entry_type']) {
            $rows->where('student_enrollments.academic_year_id', $filters['entry_type'] === 'new' ? '=' : '!=', $this->firstAcademicYear());
        }

        return [
            'rows' => $rows->orderByDesc('student_enrollments.enrollment_date')->orderByDesc('student_enrollments.id')
                ->paginate(self::PER_PAGE)->withQueryString(),
            'counts' => $counts,
        ];
    }

    /** Gender is stored on Student; category and caste are NOT recorded. */
    public function demographics(array $filters): array
    {
        $query = $this->studentQuery($filters);
        $this->dateRange($query, $filters, 'students.admission_date');
        $gender = "COALESCE(NULLIF(TRIM(students.gender), ''), 'Not recorded')";

        return [
            'rows' => (clone $query)->selectRaw($gender.' AS label, COUNT(*) AS total')
                ->groupByRaw($gender)->orderBy('label')->get(),
            'studentsCount' => (clone $query)->count(),
        ];
    }

    /** Counts include students with no documents, without fetching files. */
    public function documents(array $filters): LengthAwarePaginator
    {
        $query = $this->studentQuery($filters);
        $window = fn (Builder $documents) => $this->dateRange($documents, $filters, 'student_documents.created_at');

        if ($filters['document_status'] === 'none') {
            $query->whereDoesntHave('documents', $window);
        } elseif ($filters['document_status']) {
            $query->whereHas('documents', fn (Builder $documents) => $window($documents)->where('verification_status', $filters['document_status']));
        } elseif ($filters['from'] || $filters['to']) {
            $query->whereHas('documents', $window);
        }

        return $query->withCount([
            'documents as documents_total' => $window,
            'documents as documents_verified' => fn (Builder $documents) => $window($documents)->where('verification_status', 'verified'),
            'documents as documents_pending' => fn (Builder $documents) => $window($documents)->where('verification_status', 'pending'),
            'documents as documents_rejected' => fn (Builder $documents) => $window($documents)->where('verification_status', 'rejected'),
        ])->orderByDesc('students.created_at')->orderByDesc('students.id')
            ->paginate(self::PER_PAGE)->withQueryString();
    }

    public function promotions(array $filters): LengthAwarePaginator
    {
        $query = StudentPromotion::query()
            ->whereHas('student', fn (Builder $student) => $this->studentIdentity($student, $filters))
            ->with([
                'student', 'sourceAcademicYear', 'sourceProgram.department', 'sourceSection',
                'targetAcademicYear', 'targetProgram.department', 'targetSection', 'targetEnrollment',
            ]);

        if ($filters['academic_year_id']) {
            $query->whereHas('targetAcademicYear', fn (Builder $year) => $year->whereKey($filters['academic_year_id']));
        }
        if ($filters['program_id'] || $filters['department_id']) {
            $query->whereHas('targetProgram', fn (Builder $program) => $this->programContext($program, $filters));
        }
        if ($filters['section_id']) {
            $query->whereHas('targetSection', fn (Builder $section) => $section->whereKey($filters['section_id']));
        }
        if ($filters['promotion_status']) {
            $query->where('status', $filters['promotion_status']);
        }
        $this->dateRange($query, $filters, 'student_promotions.created_at');

        return $query->orderByDesc('student_promotions.created_at')->orderByDesc('student_promotions.id')
            ->paginate(self::PER_PAGE)->withQueryString();
    }

    public function transfers(array $filters): LengthAwarePaginator
    {
        $query = StudentTransfer::query()
            ->whereHas('student', fn (Builder $student) => $this->studentIdentity($student, $filters))
            ->with(['student', 'enrollment.academicYear', 'enrollment.program.department', 'enrollment.section']);

        if ($this->hasEnrollmentFilters($filters)) {
            $query->whereHas('enrollment', fn (Builder $enrollment) => $this->enrollmentContext($enrollment, $filters));
        }
        if ($filters['transfer_status']) {
            $query->where('status', $filters['transfer_status']);
        }
        if ($filters['tc_status']) {
            $query->where('tc_status', $filters['tc_status']);
        }
        $this->dateRange($query, $filters, 'student_transfers.transfer_date');

        return $query->orderByDesc('student_transfers.transfer_date')->orderByDesc('student_transfers.id')
            ->paginate(self::PER_PAGE)->withQueryString();
    }

    private function studentQuery(array $filters): Builder
    {
        $query = $this->studentIdentity(Student::query(), $filters);

        // All enrollment dimensions must belong to the SAME enrollment. A
        // student's year A and program B must not accidentally match together.
        if ($this->hasEnrollmentFilters($filters)) {
            $query->whereHas('enrollments', fn (Builder $enrollment) => $this->enrollmentContext($enrollment, $filters));
        }

        return $query;
    }

    private function studentIdentity(Builder $query, array $filters): Builder
    {
        if ($filters['student_status'] && $filters['student_status'] !== 'all') {
            $query->where('status', $filters['student_status']);
        }
        if ($filters['gender'] === 'not_recorded') {
            $query->where(fn (Builder $student) => $student->whereNull('students.gender')
                ->orWhereRaw("TRIM(students.gender) = ''"));
        } elseif ($filters['gender']) {
            $query->where('gender', $filters['gender']);
        }
        if ($filters['search'] !== '') {
            $this->searchStudent($query, '%'.$filters['search'].'%');
        }

        return $query;
    }

    private function searchStudent(Builder $query, string $like): Builder
    {
        return $query->where(function (Builder $student) use ($like): void {
            $student->where('student_number', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('middle_name', 'like', $like)
                ->orWhere('last_name', 'like', $like);
        });
    }

    private function enrollmentQuery(array $filters): Builder
    {
        $query = StudentEnrollment::query()
            ->whereHas('student', fn (Builder $student) => $this->studentIdentity($student, $filters));

        $this->enrollmentContext($query, $filters);
        $this->dateRange($query, $filters, 'student_enrollments.enrollment_date');

        return $query;
    }

    private function enrollmentContext(Builder $query, array $filters): Builder
    {
        if ($filters['academic_year_id']) {
            $query->whereHas('academicYear', fn (Builder $year) => $year->whereKey($filters['academic_year_id']));
        }
        if ($filters['program_id'] || $filters['department_id']) {
            $query->whereHas('program', fn (Builder $program) => $this->programContext($program, $filters));
        }
        if ($filters['section_id']) {
            $query->whereHas('section', fn (Builder $section) => $section->whereKey($filters['section_id']));
        }
        if ($filters['enrollment_status'] && $filters['enrollment_status'] !== 'all') {
            $query->where('status', $filters['enrollment_status']);
        }

        return $query;
    }

    private function programContext(Builder $query, array $filters): Builder
    {
        if ($filters['program_id']) {
            $query->whereKey($filters['program_id']);
        }
        if ($filters['department_id']) {
            $query->whereHas('department', fn (Builder $department) => $department->whereKey($filters['department_id']));
        }

        return $query;
    }

    private function hasEnrollmentFilters(array $filters): bool
    {
        foreach (['academic_year_id', 'program_id', 'department_id', 'section_id', 'enrollment_status'] as $field) {
            if ($filters[$field] && $filters[$field] !== 'all') {
                return true;
            }
        }

        return false;
    }

    private function dateRange(Builder $query, array $filters, string $column): Builder
    {
        if ($filters['from']) {
            $query->where($column, '>=', $filters['from']);
        }
        if ($filters['to']) {
            // Half-open range covers the entire final day for DATE and timestamp
            // columns alike. Eloquent's date cast can store midnight with a time
            // suffix on SQLite; a <= Y-m-d comparison would omit those rows.
            // Keep the indexed column bare instead of wrapping it in DATE().
            $query->where($column, '<', \Carbon\CarbonImmutable::parse($filters['to'])->addDay()->toDateString());
        }

        return $query;
    }

    private function firstAcademicYear(): QueryBuilder
    {
        // No global scopes on DB::table: correlate BOTH student and college,
        // and exclude soft-deleted rows explicitly. The row's first year is
        // independent of report filters (otherwise everyone would appear new).
        return DB::table('student_enrollments as first_enrollment')
            ->select('first_enrollment.academic_year_id')
            ->whereColumn('first_enrollment.college_id', 'student_enrollments.college_id')
            ->whereColumn('first_enrollment.student_id', 'student_enrollments.student_id')
            ->whereNull('first_enrollment.deleted_at')
            ->orderBy('first_enrollment.enrollment_date')->orderBy('first_enrollment.id')->limit(1);
    }
}
