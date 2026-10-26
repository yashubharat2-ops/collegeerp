<?php

namespace App\Domain\Student\Services;

use App\Domain\Student\Filters\StudentFilter;
use App\Models\Student;
use App\Support\Listing\ListQueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The single query pipeline behind the Student list, CSV, PDF and print report.
 *
 * Canonical student/enrollment semantics are delegated to StudentFilter. The
 * generic ListQueryBuilder still owns free-text search, legacy profile filters,
 * allow-listed sorting, stable pagination and query-string preservation.
 */
class StudentListService
{
    public const PER_PAGE = 15;

    public const SORTS = [
        'student_number' => 'students.student_number',
        'name' => 'students.first_name',
        'email' => 'students.email',
        'admission_date' => 'students.admission_date',
        'status' => 'students.status',
        'created_at' => 'students.created_at',
    ];

    public function __construct(private readonly StudentFilter $studentFilter)
    {
    }

    /** @return array<string, mixed> */
    public function filters(Request $request): array
    {
        return $this->studentFilter->fromRequest($request, studentListAliases: true);
    }

    /**
     * Build the filter/search/sort-configured query. No order clause is added
     * until the caller paginates or explicitly applies the configured sort.
     */
    public function builder(Request $request, ?array $filters = null): ListQueryBuilder
    {
        $filters ??= $this->filters($request);

        $query = Student::query()->with([
            'enrollments.academicYear',
            'enrollments.program.department',
            'enrollments.section',
        ]);

        // Normal operational list: legacy (NULL) and approved registrations only.
        // An explicit registration_status filter is an administrative review of
        // pending/rejected registrations and overrides the default visibility.
        if (! isset($filters['registration_status'])) {
            $query->approvedForOperations();
        }

        $this->studentFilter->applyToStudents($query, $filters);

        $builder = ListQueryBuilder::for($query, $request)->addAppliedFilters($filters);

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

        // Existing profile filters remain available alongside the canonical set.
        $builder->filterIn('gender', 'students.gender', Student::GENDERS);
        $builder->filterIn('category', 'students.category', Student::CATEGORIES);
        $builder->filterIn('blood_group', 'students.blood_group', Student::BLOOD_GROUPS);
        $builder->filterContains('nationality', 'students.nationality');
        $builder->filterContains('mother_tongue', 'students.mother_tongue');
        $builder->filterDateRange('date_of_birth_from', 'date_of_birth_to', 'students.date_of_birth');

        return $builder->sorts(self::SORTS, 'created_at', 'asc');
    }

    public function paginate(Request $request, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $this->builder($request)
            ->tiebreaker('students.id')
            ->paginate($perPage);
    }

    /** @return array<string, mixed> */
    public function appliedFilters(Request $request): array
    {
        return $this->builder($request)->getAppliedFilters();
    }

    /** @return array<string, mixed> */
    public function filterViewData(array $filters): array
    {
        return $this->studentFilter->viewData($filters, StudentFilter::STUDENT_LIST_FIELDS);
    }

    public function canonical(): StudentFilter
    {
        return $this->studentFilter;
    }
}
