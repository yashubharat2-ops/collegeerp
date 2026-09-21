<?php

namespace App\Services\Examinations;

use App\Models\ExamResult;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * ResultQueryService — the read side of the Results module
 * (Examinations Phase 3).
 *
 * Results only ever READ calculated data: nothing here writes marks or results.
 *
 * Two rules that shape every query:
 *
 *  1. Tenant safety — ExamResult carries CollegeScope, so every query is
 *     already restricted to the active college; the additional dropdown
 *     filters are validated to belong to that same college before use.
 *  2. Unpublished results are never exposed as published ones — a user without
 *     the `results.view_unpublished` permission only ever sees published rows.
 */
class ResultQueryService
{
    /**
     * Tenant-scoped, filtered, deterministically paginated result list.
     *
     * @param  bool  $canViewUnpublished  From the policy, never from the request.
     */
    public function paginate(Request $request, bool $canViewUnpublished): LengthAwarePaginator
    {
        return $this->applyFilters($this->baseQuery($canViewUnpublished), $request, $canViewUnpublished)
            // Deterministic pagination order.
            ->orderByDesc('id')
            ->with([
                'examination',
                'academicTerm',
                'academicYear',
                'studentEnrollment.student',
                'studentEnrollment.program',
                'studentEnrollment.section',
                'gradeScale',
                'publishedBy',
            ])
            ->paginate(15)
            ->withQueryString();
    }

    /**
     * @param  bool  $canViewUnpublished
     */
    public function baseQuery(bool $canViewUnpublished = true): Builder
    {
        $query = ExamResult::query();

        if (! $canViewUnpublished) {
            $query->whereNotNull('published_at');
        }

        return $query;
    }

    /**
     * Apply the supported list filters.
     *
     * Every filter value is validated against the ACTIVE college through the
     * tenant-scoped filter option lists, so a forged foreign id can only ever
     * produce an empty result set — never cross-tenant data.
     */
    public function applyFilters(Builder $query, Request $request, bool $canViewUnpublished): Builder
    {
        $filters = $this->currentFilters($request);

        if ($filters['examination_id']) {
            $query->where('examination_id', (int) $filters['examination_id']);
        }

        if ($filters['academic_year_id']) {
            $query->where('academic_year_id', (int) $filters['academic_year_id']);
        }

        if ($filters['academic_term_id']) {
            $query->where('academic_term_id', (int) $filters['academic_term_id']);
        }

        if ($filters['program_id'] || $filters['section_id']) {
            $query->whereHas('studentEnrollment', function (Builder $enrollment) use ($filters): void {
                if ($filters['program_id']) {
                    $enrollment->where('program_id', (int) $filters['program_id']);
                }
                if ($filters['section_id']) {
                    $enrollment->where('section_id', (int) $filters['section_id']);
                }
            });
        }

        if ($filters['search'] !== null && $filters['search'] !== '') {
            $term = '%'.mb_strtolower($filters['search']).'%';
            $query->whereHas('studentEnrollment', function (Builder $enrollment) use ($term): void {
                $enrollment
                    ->whereRaw('LOWER(enrollment_number) LIKE ?', [$term])
                    ->orWhereHas('student', function (Builder $student) use ($term): void {
                        $student
                            ->whereRaw('LOWER(student_number) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(first_name) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', [$term]);
                    });
            });
        }

        if ($filters['result_status'] && in_array($filters['result_status'], ExamResult::RESULT_STATUSES, true)) {
            $query->where('result_status', $filters['result_status']);
        }

        if ($filters['calculation_status'] && in_array($filters['calculation_status'], ExamResult::CALCULATION_STATUSES, true)) {
            $query->where('calculation_status', $filters['calculation_status']);
        }

        // Published / unpublished filter. Unpublished rows stay invisible to a
        // user who may not see them, whatever the filter says.
        if ($filters['publication_status'] === ExamResult::PUBLICATION_PUBLISHED) {
            $query->whereNotNull('published_at');
        } elseif ($filters['publication_status'] === ExamResult::PUBLICATION_UNPUBLISHED) {
            if (! $canViewUnpublished) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereNull('published_at');
            }
        }

        return $query;
    }

    public function currentFilters(Request $request): array
    {
        return [
            'examination_id' => $request->input('examination_id'),
            'academic_year_id' => $request->input('academic_year_id'),
            'academic_term_id' => $request->input('academic_term_id'),
            'program_id' => $request->input('program_id'),
            'section_id' => $request->input('section_id'),
            'search' => trim((string) $request->input('search', '')),
            'result_status' => $request->input('result_status'),
            'calculation_status' => $request->input('calculation_status'),
            'publication_status' => $request->input('publication_status'),
        ];
    }
}
