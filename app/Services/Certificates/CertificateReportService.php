<?php

namespace App\Services\Certificates;

use App\Models\Certificate;
use App\Models\CertificateType;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * CertificateReportService — read-only live aggregations over the existing
 * tenant-scoped Certificate Management tables (`certificate_types`,
 * `certificate_templates`, `certificates`, `students`, `student_enrollments`).
 *
 * Never mutates any record and creates no duplicate reporting tables.
 */
class CertificateReportService
{
    private const PER_PAGE = 15;

    /**
     * 1. Certificate Request Report
     *
     * @param  array<string, mixed>  $filters
     * @return array{certificates: LengthAwarePaginator, totals: array<string, int>}
     */
    public function requests(array $filters, int $perPage = self::PER_PAGE): array
    {
        $base = $this->baseRequestQuery($filters);

        $aggregates = (clone $base)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'requested' THEN 1 ELSE 0 END), 0) as requested")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'generated' THEN 1 ELSE 0 END), 0) as generated")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'issued' THEN 1 ELSE 0 END), 0) as issued")
            ->first();

        $certificates = (clone $base)
            ->with([
                'type:id,college_id,name,code,builtin_key,is_active',
                'student:id,college_id,student_number,first_name,middle_name,last_name,status',
                'enrollment:id,college_id,student_id,academic_year_id,program_id,section_id,enrollment_number,enrollment_date,status',
                'enrollment.academicYear:id,college_id,name,code',
                'enrollment.program:id,college_id,name,code',
                'enrollment.section:id,college_id,name,code',
                'requester:id,name',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return [
            'certificates' => $certificates,
            'totals' => [
                'total' => (int) ($aggregates->total ?? 0),
                'requested' => (int) ($aggregates->requested ?? 0),
                'generated' => (int) ($aggregates->generated ?? 0),
                'issued' => (int) ($aggregates->issued ?? 0),
            ],
        ];
    }

    /**
     * 2. Certificate Issuance Report
     *
     * @param  array<string, mixed>  $filters
     * @return array{certificates: LengthAwarePaginator, totals: array<string, int>}
     */
    public function issuance(array $filters, int $perPage = self::PER_PAGE): array
    {
        $base = $this->baseIssuanceQuery($filters);

        $aggregates = (clone $base)
            ->selectRaw('COUNT(*) as total_issued')
            ->selectRaw('COALESCE(SUM(CASE WHEN verification_count > 0 OR last_verified_at IS NOT NULL THEN 1 ELSE 0 END), 0) as verified_issued')
            ->selectRaw('COALESCE(SUM(CASE WHEN verification_count = 0 AND last_verified_at IS NULL THEN 1 ELSE 0 END), 0) as unverified_issued')
            ->first();

        $certificates = (clone $base)
            ->with([
                'type:id,college_id,name,code,builtin_key,is_active',
                'template:id,college_id,certificate_type_id,name',
                'student:id,college_id,student_number,first_name,middle_name,last_name,status',
                'enrollment:id,college_id,student_id,academic_year_id,program_id,section_id,enrollment_number,status',
                'enrollment.academicYear:id,college_id,name,code',
                'enrollment.program:id,college_id,name,code',
                'issuer:id,name',
            ])
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return [
            'certificates' => $certificates,
            'totals' => [
                'total_issued' => (int) ($aggregates->total_issued ?? 0),
                'verified_issued' => (int) ($aggregates->verified_issued ?? 0),
                'unverified_issued' => (int) ($aggregates->unverified_issued ?? 0),
            ],
        ];
    }

    /**
     * 3. Certificate Verification Report
     *
     * @param  array<string, mixed>  $filters
     * @return array{certificates: LengthAwarePaginator, totals: array<string, int>}
     */
    public function verification(array $filters, int $perPage = self::PER_PAGE): array
    {
        $base = $this->baseVerificationQuery($filters);

        $aggregates = (clone $base)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN verification_count > 0 OR last_verified_at IS NOT NULL THEN 1 ELSE 0 END), 0) as verified')
            ->selectRaw('COALESCE(SUM(CASE WHEN verification_count = 0 AND last_verified_at IS NULL THEN 1 ELSE 0 END), 0) as unverified')
            ->selectRaw('COALESCE(SUM(verification_count), 0) as total_lookups')
            ->first();

        $certificates = (clone $base)
            ->with([
                'type:id,college_id,name,code,builtin_key,is_active',
                'student:id,college_id,student_number,first_name,middle_name,last_name,status',
                'enrollment:id,college_id,student_id,academic_year_id,program_id,enrollment_number,status',
                'enrollment.academicYear:id,college_id,name,code',
                'enrollment.program:id,college_id,name,code',
                'lastVerifier:id,name',
            ])
            ->orderByDesc('last_verified_at')
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return [
            'certificates' => $certificates,
            'totals' => [
                'total' => (int) ($aggregates->total ?? 0),
                'verified' => (int) ($aggregates->verified ?? 0),
                'unverified' => (int) ($aggregates->unverified ?? 0),
                'total_lookups' => (int) ($aggregates->total_lookups ?? 0),
            ],
        ];
    }

    /**
     * 4. Certificate Type-wise Report
     *
     * @param  array<string, mixed>  $filters
     * @return array{typeRows: Collection<int, CertificateType>, totals: array<string, int>}
     */
    public function typeWise(array $filters): array
    {
        $typeRows = $this->queryTypeBreakdown($filters);

        return [
            'typeRows' => $typeRows,
            'totals' => [
                'total_types' => $typeRows->count(),
                'total_requests' => (int) $typeRows->sum('total_count'),
                'requested' => (int) $typeRows->sum('requested_count'),
                'generated' => (int) $typeRows->sum('generated_count'),
                'issued' => (int) $typeRows->sum('issued_count'),
                'verified' => (int) $typeRows->sum('verified_count'),
                'verification_lookups' => (int) $typeRows->sum(fn (CertificateType $t) => (int) ($t->verification_lookups_sum ?? 0)),
            ],
        ];
    }

    /**
     * 5. Certificate Summary
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        $base = Certificate::query()
            ->when($filters['certificate_type_id'] ?? null, fn (Builder $q, int $id) => $q->where('certificate_type_id', $id));

        $this->applyDateRange($base, $filters['date_from'] ?? null, $filters['date_to'] ?? null, 'created_at');

        $aggregates = (clone $base)
            ->selectRaw('COUNT(*) as total_requests')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'requested' THEN 1 ELSE 0 END), 0) as pending_requests")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'generated' THEN 1 ELSE 0 END), 0) as generated_certificates")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'issued' THEN 1 ELSE 0 END), 0) as total_issued")
            ->selectRaw('COALESCE(SUM(CASE WHEN verification_count > 0 OR last_verified_at IS NOT NULL THEN 1 ELSE 0 END), 0) as total_verified')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'issued' AND verification_count = 0 AND last_verified_at IS NULL THEN 1 ELSE 0 END), 0) as unverified_issued")
            ->selectRaw('COALESCE(SUM(verification_count), 0) as total_verification_lookups')
            ->first();

        $byType = $this->queryTypeBreakdown($filters);

        return [
            'total_requests' => (int) ($aggregates->total_requests ?? 0),
            'pending_requests' => (int) ($aggregates->pending_requests ?? 0),
            'generated_certificates' => (int) ($aggregates->generated_certificates ?? 0),
            'total_issued' => (int) ($aggregates->total_issued ?? 0),
            'total_verified' => (int) ($aggregates->total_verified ?? 0),
            'unverified_issued' => (int) ($aggregates->unverified_issued ?? 0),
            'total_verification_lookups' => (int) ($aggregates->total_verification_lookups ?? 0),
            'total_types' => $byType->count(),
            'active_types' => $byType->where('is_active', true)->count(),
            'by_type' => $byType,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, CertificateType>
     */
    private function queryTypeBreakdown(array $filters): Collection
    {
        $dateScope = function (Builder $query) use ($filters): void {
            $this->applyDateRange($query, $filters['date_from'] ?? null, $filters['date_to'] ?? null, 'created_at');
        };

        return CertificateType::query()
            ->when($filters['certificate_type_id'] ?? null, fn (Builder $q, int $id) => $q->whereKey($id))
            ->when($filters['search'] ?? null, function (Builder $q, string $search): void {
                $like = '%' . $search . '%';
                $q->where(function (Builder $sub) use ($like): void {
                    $sub->where('name', 'like', $like)
                        ->orWhere('code', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->withCount([
                'certificates as total_count' => fn (Builder $q) => $dateScope($q),
                'certificates as requested_count' => fn (Builder $q) => $dateScope($q->where('status', 'requested')),
                'certificates as generated_count' => fn (Builder $q) => $dateScope($q->where('status', 'generated')),
                'certificates as issued_count' => fn (Builder $q) => $dateScope($q->where('status', 'issued')),
                'certificates as verified_count' => fn (Builder $q) => $dateScope(
                    $q->where(fn (Builder $v) => $v->where('verification_count', '>', 0)->orWhereNotNull('last_verified_at'))
                ),
                'templates as templates_count',
            ])
            ->withSum([
                'certificates as verification_lookups_sum' => fn (Builder $q) => $dateScope($q),
            ], 'verification_count')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function baseRequestQuery(array $filters): Builder
    {
        $query = Certificate::query()
            ->when($filters['certificate_type_id'] ?? null, fn (Builder $q, int $id) => $q->where('certificate_type_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['student_id'] ?? null, fn (Builder $q, int $studentId) => $q->where('student_id', $studentId))
            ->when($filters['academic_year_id'] ?? null, function (Builder $q, int $yearId): void {
                $q->whereHas('enrollment', fn (Builder $e) => $e->where('academic_year_id', $yearId));
            })
            ->when($filters['program_id'] ?? null, function (Builder $q, int $programId): void {
                $q->whereHas('enrollment', fn (Builder $e) => $e->where('program_id', $programId));
            });

        $this->applyDateRange($query, $filters['date_from'] ?? null, $filters['date_to'] ?? null, 'created_at');
        $this->applyCertificateSearch($query, $filters['search'] ?? null);

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function baseIssuanceQuery(array $filters): Builder
    {
        $query = Certificate::query()
            ->where('status', 'issued')
            ->when($filters['certificate_type_id'] ?? null, fn (Builder $q, int $id) => $q->where('certificate_type_id', $id))
            ->when($filters['student_id'] ?? null, fn (Builder $q, int $studentId) => $q->where('student_id', $studentId));

        $this->applyDateRangeWithFallback($query, $filters['date_from'] ?? null, $filters['date_to'] ?? null, 'issued_at', 'created_at');
        $this->applyCertificateSearch($query, $filters['search'] ?? null);

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function baseVerificationQuery(array $filters): Builder
    {
        $query = Certificate::query()
            ->where(function (Builder $q): void {
                $q->where('status', 'issued')
                    ->orWhere('verification_count', '>', 0)
                    ->orWhereNotNull('last_verified_at');
            })
            ->when($filters['certificate_type_id'] ?? null, fn (Builder $q, int $id) => $q->where('certificate_type_id', $id))
            ->when($filters['student_id'] ?? null, fn (Builder $q, int $studentId) => $q->where('student_id', $studentId))
            ->when($filters['verification_status'] ?? null, function (Builder $q, string $verificationStatus): void {
                if ($verificationStatus === 'verified') {
                    $q->where(function (Builder $v): void {
                        $v->where('verification_count', '>', 0)
                            ->orWhereNotNull('last_verified_at');
                    });
                } elseif ($verificationStatus === 'unverified') {
                    $q->where('verification_count', 0)->whereNull('last_verified_at');
                }
            });

        $this->applyVerificationDateRange($query, $filters['date_from'] ?? null, $filters['date_to'] ?? null);
        $this->applyCertificateSearch($query, $filters['search'] ?? null);

        return $query;
    }

    private function applyDateRange(Builder $query, ?CarbonInterface $from, ?CarbonInterface $to, string $column): void
    {
        if ($from !== null) {
            $query->whereDate($column, '>=', $from->toDateString());
        }

        if ($to !== null) {
            $query->whereDate($column, '<=', $to->toDateString());
        }
    }

    private function applyDateRangeWithFallback(Builder $query, ?CarbonInterface $from, ?CarbonInterface $to, string $primary, string $fallback): void
    {
        if ($from !== null) {
            $fromDate = $from->toDateString();
            $query->where(function (Builder $q) use ($primary, $fallback, $fromDate): void {
                $q->whereDate($primary, '>=', $fromDate)
                    ->orWhere(function (Builder $sub) use ($primary, $fallback, $fromDate): void {
                        $sub->whereNull($primary)->whereDate($fallback, '>=', $fromDate);
                    });
            });
        }

        if ($to !== null) {
            $toDate = $to->toDateString();
            $query->where(function (Builder $q) use ($primary, $fallback, $toDate): void {
                $q->whereDate($primary, '<=', $toDate)
                    ->orWhere(function (Builder $sub) use ($primary, $fallback, $toDate): void {
                        $sub->whereNull($primary)->whereDate($fallback, '<=', $toDate);
                    });
            });
        }
    }

    private function applyVerificationDateRange(Builder $query, ?CarbonInterface $from, ?CarbonInterface $to): void
    {
        if ($from !== null) {
            $fromDate = $from->toDateString();
            $query->where(function (Builder $q) use ($fromDate): void {
                $q->whereDate('last_verified_at', '>=', $fromDate)
                    ->orWhere(function (Builder $sub) use ($fromDate): void {
                        $sub->whereNull('last_verified_at')
                            ->where(function (Builder $inner) use ($fromDate): void {
                                $inner->whereDate('issued_at', '>=', $fromDate)
                                    ->orWhere(function (Builder $fallback) use ($fromDate): void {
                                        $fallback->whereNull('issued_at')->whereDate('created_at', '>=', $fromDate);
                                    });
                            });
                    });
            });
        }

        if ($to !== null) {
            $toDate = $to->toDateString();
            $query->where(function (Builder $q) use ($toDate): void {
                $q->whereDate('last_verified_at', '<=', $toDate)
                    ->orWhere(function (Builder $sub) use ($toDate): void {
                        $sub->whereNull('last_verified_at')
                            ->where(function (Builder $inner) use ($toDate): void {
                                $inner->whereDate('issued_at', '<=', $toDate)
                                    ->orWhere(function (Builder $fallback) use ($toDate): void {
                                        $fallback->whereNull('issued_at')->whereDate('created_at', '<=', $toDate);
                                    });
                            });
                    });
            });
        }
    }

    private function applyCertificateSearch(Builder $query, ?string $search): void
    {
        if ($search === null || $search === '') {
            return;
        }

        $like = '%' . $search . '%';
        $numericId = ltrim($search, '#');
        $idMatch = ctype_digit($numericId) && (int) $numericId > 0 ? (int) $numericId : null;

        $query->where(function (Builder $q) use ($like, $idMatch): void {
            $q->where('number', 'like', $like)
                ->orWhere('purpose', 'like', $like)
                ->orWhereHas('student', function (Builder $studentQuery) use ($like): void {
                    $studentQuery->where('first_name', 'like', $like)
                        ->orWhere('middle_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('student_number', 'like', $like);
                })
                ->orWhereHas('enrollment', function (Builder $enrollmentQuery) use ($like): void {
                    $enrollmentQuery->where('enrollment_number', 'like', $like);
                })
                ->orWhereHas('type', function (Builder $typeQuery) use ($like): void {
                    $typeQuery->where('name', 'like', $like)
                        ->orWhere('code', 'like', $like);
                });

            if ($idMatch !== null) {
                $q->orWhere('id', $idMatch);
            }
        });
    }
}
