<?php

namespace App\Domain\Student\Services;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Support\Listing\ListQueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The single query pipeline behind the Student list AND its export.
 *
 * Both screens must show/export the same rows for the same query string, so the
 * search, the filter set and the sort allow-list live here once and are consumed
 * through the shared `ListQueryBuilder` (never re-implemented in a controller or
 * a view). `StudentController::index`, `StudentController::export` and the
 * tests all use this class, which is why a filter can never apply on screen but
 * silently disappear from the CSV.
 *
 * Tenant safety: the root query is `Student::query()`, which carries
 * CollegeScope, and every related dimension is reached through a tenant-scoped
 * relation (enrollments → academicYear/program/section/academicRecords are all
 * CollegeScope models). A foreign college's id therefore matches nothing instead
 * of widening the result set, and no value from the request is ever interpolated
 * into SQL — ids are digit-checked and enum values go through allow-lists.
 *
 * Nothing is denormalised or cached here: the list is a live query over the
 * existing Student/StudentEnrollment/academic masters, exactly as the module's
 * foundation requires.
 */
class StudentListService
{
    /**
     * Default page size — deliberately the Students module's historical 15, so
     * the existing pagination contract is unchanged.
     */
    public const PER_PAGE = 15;

    /**
     * Columns the list may be sorted by. The map is an allow-list: an unknown
     * `sort` value is ignored (the default order applies), never passed through
     * to the query.
     */
    public const SORTS = [
        'student_number' => 'students.student_number',
        'name' => 'students.first_name',
        'email' => 'students.email',
        'admission_date' => 'students.admission_date',
        'status' => 'students.status',
        'created_at' => 'students.created_at',
    ];

    /**
     * Filters that all describe ONE enrollment.
     *
     * They are applied inside a single `whereHas('enrollments', …)`, so
     * "academic year 2026 + program BSc" means one enrollment that is both —
     * not a student enrolled in 2026 *and* (separately) somewhere in BSc.
     */
    public const ENROLLMENT_FILTERS = [
        'academic_year_id',
        'department_id',
        'program_id',
        'section_id',
        'enrollment_status',
    ];

    /**
     * The academic term parameter.
     *
     * A term is NOT a column of StudentEnrollment: terms live on
     * StudentAcademicRecord (the progression ledger), where the enrollment link
     * is optional. The term filter is therefore applied on the student's
     * academic records — see applyTermFilter().
     */
    public const TERM_FILTER = 'academic_term_id';

    /**
     * Build the filter/search/sort-configured query for the current request.
     *
     * No order clause is applied yet: callers either paginate (which adds the
     * configured sort plus the id tiebreak) or stream the same filter set.
     */
    public function builder(Request $request): ListQueryBuilder
    {
        $builder = ListQueryBuilder::for(
            Student::query()->with([
                'enrollments.academicYear',
                'enrollments.program.department',
                'enrollments.section',
            ]),
            $request
        );

        // Search: student name (parts), student number, e-mail and mobile on the
        // student itself, plus the enrollment number on the student's enrollments.
        // One term, one AND-group, so it composes with every filter below.
        $builder->search(
            [
                'students.first_name',
                'students.middle_name',
                'students.last_name',
                'students.student_number',
                'students.email',
                'students.phone',
                'students.alternate_phone',
            ],
            'search',
            ['enrollments' => ['enrollment_number']]
        );

        // Identity / lifecycle filters. Each value is whitelisted, so an unknown
        // value is ignored rather than rejected or interpolated.
        $builder->filterIn('gender', 'students.gender', Student::GENDERS);
        $builder->filterIn('category', 'students.category', Student::CATEGORIES);
        $builder->filterIn('status', 'students.status', Student::STATUSES);

        // Admission date: one exact day, or a from/to range (both half-open safe
        // through the builder's whereDate handling).
        $builder->filterDate('admission_date', 'students.admission_date');
        $builder->filterDateRange('admission_date_from', 'admission_date_to', 'students.admission_date');

        // Academic context: one correlated enrollment subquery for every
        // enrollment dimension, so they can never match different enrollments.
        $builder->filterAny(self::ENROLLMENT_FILTERS, function (Builder $query, array $present): void {
            $this->applyEnrollmentContext($query, $present);
        });

        // Academic term / semester: matched on the academic records (that is
        // where a term exists — StudentEnrollment has no term column), with the
        // selected year applied to the same record when both are given, so a term
        // can never be read as belonging to another year.
        $builder->filterAny([self::TERM_FILTER], function (Builder $query, array $present) use ($request): void {
            $this->applyTermFilter($query, $present, $this->numericId($request->input('academic_year_id')));
        });

        return $builder->sorts(self::SORTS, 'created_at', 'asc');
    }

    /**
     * Paginate the filtered list in creation order (the module's historical
     * default) with a stable id tiebreak, preserving the query string.
     */
    public function paginate(Request $request, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $this->builder($request)
            ->tiebreaker('students.id')
            ->paginate($perPage);
    }

    /**
     * The full active filter state, for ListContext / the bulk-action redirect.
     *
     * @return array<string, mixed>
     */
    public function appliedFilters(Request $request): array
    {
        return $this->builder($request)->getAppliedFilters();
    }

    /**
     * Tenant-scoped option lists for the filter form.
     *
     * Every model here carries CollegeScope, so the dropdowns can only ever
     * offer the active college's masters — no college_id is taken from the
     * request. Sections/terms include their year/program so the option text can
     * disambiguate two sections with the same name.
     *
     * @return array<string, Collection<int, \Illuminate\Database\Eloquent\Model>>
     */
    public function filterOptions(): array
    {
        return [
            'academicYears' => AcademicYear::query()
                ->orderByDesc('starts_on')->orderByDesc('id')
                ->get(['id', 'name', 'code']),

            'departments' => Department::query()
                ->orderBy('name')->orderBy('id')
                ->get(['id', 'name', 'code']),

            'programs' => Program::query()
                ->orderBy('name')->orderBy('id')
                ->get(['id', 'name', 'code', 'department_id']),

            'academicTerms' => AcademicTerm::query()
                ->with(['academicYear:id,name'])
                ->orderByDesc('academic_year_id')->orderBy('sequence')->orderBy('id')
                ->get(['id', 'name', 'code', 'academic_year_id']),

            'sections' => Section::query()
                ->with(['academicYear:id,name', 'program:id,name'])
                ->orderBy('name')->orderBy('id')
                ->get(['id', 'name', 'code', 'academic_year_id', 'program_id']),
        ];
    }

    /**
     * Apply EVERY enrollment dimension to one `whereHas('enrollments')` closure.
     *
     * Unusable values (non-numeric ids, unknown statuses) are dropped here; a
     * request that only carries unusable values therefore adds no constraint at
     * all instead of matching nothing.
     *
     * @param array<string, mixed> $present
     */
    private function applyEnrollmentContext(Builder $studentQuery, array $present): void
    {
        $academicYearId = $this->numericId($present['academic_year_id'] ?? null);
        $departmentId = $this->numericId($present['department_id'] ?? null);
        $programId = $this->numericId($present['program_id'] ?? null);
        $sectionId = $this->numericId($present['section_id'] ?? null);
        $statuses = $this->enrollmentStatuses($present['enrollment_status'] ?? null);

        if ($academicYearId === null && $departmentId === null && $programId === null
            && $sectionId === null && $statuses === []) {
            return;
        }

        $studentQuery->whereHas('enrollments', function (Builder $enrollment) use (
            $academicYearId,
            $departmentId,
            $programId,
            $sectionId,
            $statuses
        ): void {
            if ($academicYearId !== null) {
                $enrollment->where('student_enrollments.academic_year_id', $academicYearId);
            }

            if ($programId !== null) {
                $enrollment->where('student_enrollments.program_id', $programId);
            }

            if ($departmentId !== null) {
                $enrollment->whereHas('program', fn (Builder $program) => $program->where('programs.department_id', $departmentId));
            }

            if ($sectionId !== null) {
                $enrollment->where('student_enrollments.section_id', $sectionId);
            }

            if ($statuses !== []) {
                $enrollment->whereIn('student_enrollments.status', $statuses);
            }
        });
    }

    /**
     * Academic term / semester.
     *
     * Terms live on the student's academic records, not on the enrollment (the
     * enrollment deliberately has no term column — see
     * docs/student_management_foundation_design.md), and a record's enrollment
     * link is optional. The term is therefore matched where it actually is, and
     * the record must also be in the selected academic year when one is given:
     * a term belongs to exactly one year, so that check can only ever reject a
     * genuinely inconsistent combination, never a legitimate row.
     *
     * @param array<string, mixed> $present
     */
    private function applyTermFilter(Builder $studentQuery, array $present, ?int $academicYearId): void
    {
        $termId = $this->numericId($present[self::TERM_FILTER] ?? null);

        if ($termId === null) {
            return;
        }

        $studentQuery->whereHas('academicRecords', function (Builder $record) use ($termId, $academicYearId): void {
            $record->where('student_academic_records.academic_term_id', $termId);

            if ($academicYearId !== null) {
                $record->where('student_academic_records.academic_year_id', $academicYearId);
            }
        });
    }

    /**
     * A single numeric id from the query string, or null when the value is not a
     * plain positive integer (arrays, "1 OR 1=1", floats and junk are ignored).
     */
    private function numericId(mixed $value): ?int
    {
        if (is_array($value) || is_bool($value) || $value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * Enrollment statuses from a single value or a comma-separated list,
     * intersected with the model's allow-list.
     *
     * @return array<int, string>
     */
    private function enrollmentStatuses(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        // Non-scalars (e.g. a nested array from a hand-crafted query string) are
        // never stringified into a value, they are simply dropped.
        $items = array_map(fn ($item) => is_scalar($item) ? trim((string) $item) : '', $items);

        return array_values(array_intersect($items, StudentEnrollment::STATUSES));
    }
}
