<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\FeeStructure;
use App\Models\FeeStructureItem;
use App\Models\Program;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Feature\ExamAttendance\ExamAttendanceTestHelpers;

/**
 * Shared fixtures for the Finance / Fees — Fee Structure tests.
 *
 * Reuses the project-wide fixtures (college, RBAC users, super admin) instead of
 * duplicating them, and adds only what the Finance module owns: the academic
 * year / term / program context a fee structure references, and fee structures
 * with their fee heads.
 *
 * The Finance module deliberately owns NO academic masters: the year, term and
 * program created below are the Platform module's rows.
 */
trait FeeStructureTestHelpers
{
    use ExamAttendanceTestHelpers;

    /**
     * Academic year / term / program fixture for one college.
     *
     * @return array{year: AcademicYear, term: AcademicTerm, prog: Program}
     */
    private function makeFinanceContext(College $college, string $prefix): array
    {
        $year = AcademicYear::create([
            'college_id' => $college->id,
            'name' => "2026 {$prefix}",
            'code' => "AY-{$prefix}",
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);

        $term = AcademicTerm::create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'name' => "Term {$prefix}",
            'code' => "T-{$prefix}",
            'type' => 'term',
            'sequence' => 1,
            'status' => 'active',
        ]);

        $prog = Program::create([
            'college_id' => $college->id,
            'name' => "Prog {$prefix}",
            'code' => "P-{$prefix}",
            'status' => 'active',
        ]);

        return compact('year', 'term', 'prog');
    }

    /**
     * A fee structure with its fee heads.
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<int, array>|null  $items
     */
    private function makeFeeStructure(College $college, array $ctx, array $overrides = [], ?array $items = null): FeeStructure
    {
        $structure = FeeStructure::create(array_merge([
            'college_id' => $college->id,
            'academic_year_id' => $ctx['year']->id,
            'program_id' => $ctx['prog']->id,
            'academic_term_id' => $ctx['term']->id,
            'name' => 'Fee Plan '.Str::upper(Str::random(4)),
            'code' => 'FS-'.Str::upper(Str::random(6)),
            'status' => FeeStructure::STATUS_ACTIVE,
            'description' => 'Test fee structure',
        ], $overrides));

        foreach ($items ?? $this->defaultFeeItems() as $index => $item) {
            $structure->allItems()->create([
                'college_id' => $college->id,
                'name' => $item['name'],
                'amount' => $item['amount'],
                'description' => $item['description'] ?? null,
                'sort_order' => $item['sort_order'] ?? $index + 1,
                'status' => $item['status'] ?? FeeStructureItem::STATUS_ACTIVE,
            ]);
        }

        return $structure->refresh();
    }

    /**
     * A minimal fee component set (test data only).
     *
     * @return array<int, array>
     */
    private function defaultFeeItems(): array
    {
        return [
            ['name' => 'Tuition Fee', 'amount' => 25000, 'sort_order' => 1],
            ['name' => 'Library Fee', 'amount' => 1500, 'sort_order' => 2],
        ];
    }

    /**
     * The HTTP payload for creating/updating a fee structure.
     *
     * @param  array{year: AcademicYear, term: AcademicTerm, prog: Program}  $ctx
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function feeStructurePayload(array $ctx, array $overrides = []): array
    {
        return array_merge([
            'academic_year_id' => $ctx['year']->id,
            'program_id' => $ctx['prog']->id,
            'academic_term_id' => $ctx['term']->id,
            'name' => 'Undergraduate Fee Plan',
            'code' => 'FS-UG-2026',
            'status' => FeeStructure::STATUS_ACTIVE,
            'description' => 'Annual fee plan',
            'items' => [
                ['name' => 'Tuition Fee', 'amount' => 25000, 'sort_order' => 1, 'status' => 'active'],
                ['name' => 'Library Fee', 'amount' => 1500, 'sort_order' => 2, 'status' => 'active'],
            ],
        ], $overrides);
    }

    /**
     * Run a callback with the tenant context bound to $college.
     *
     * Every Finance model carries CollegeScope, which resolves to
     * `whereRaw('1 = 0')` when no tenant is active. Assertions that read these
     * models directly (outside an HTTP request) therefore have to pin the
     * tenant explicitly — inside a request the `tenant` middleware does it.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withTenant(College $college, callable $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->set($college);

        try {
            return $callback();
        } finally {
            $context->clear();
        }
    }
}
