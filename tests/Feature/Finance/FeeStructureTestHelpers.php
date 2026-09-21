<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Services\FeeCollectionService;
use App\Domain\Finance\Services\StudentFeeAssignmentService;
use App\Domain\Finance\Support\FeeLedger;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\FeeCategory;
use App\Models\FeePayment;
use App\Models\FeeStructure;
use App\Models\FeeStructureItem;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentFeeAssignment;
use App\Models\User;
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
                // Optional classification, exactly like the fee structure form.
                'fee_category_id' => $item['fee_category_id'] ?? null,
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

    /**
     * A fee category for one college.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeFeeCategory(College $college, array $overrides = []): FeeCategory
    {
        return FeeCategory::create(array_merge([
            'college_id' => $college->id,
            'name' => 'Tuition '.Str::upper(Str::random(4)),
            'code' => 'FC-'.Str::upper(Str::random(6)),
            'description' => 'Test fee category',
            'status' => FeeCategory::STATUS_ACTIVE,
        ], $overrides));
    }

    /**
     * A student with an enrollment inside the finance fixture context.
     *
     * @param  array{year: AcademicYear, term: AcademicTerm, prog: Program}  $ctx
     * @param  array<string, mixed>  $enrollmentOverrides
     * @return array{student: Student, enrollment: StudentEnrollment}
     */
    private function makeFinanceEnrollment(College $college, array $ctx, string $suffix, array $enrollmentOverrides = []): array
    {
        $student = Student::create([
            'college_id' => $college->id,
            'student_number' => 'STU-'.$suffix.'-'.Str::upper(Str::random(4)),
            'first_name' => 'Fin',
            'last_name' => $suffix,
            'status' => 'active',
        ]);

        $enrollment = StudentEnrollment::create(array_merge([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'academic_year_id' => $ctx['year']->id,
            'program_id' => $ctx['prog']->id,
            'enrollment_number' => 'ENR-'.$suffix.'-'.Str::upper(Str::random(4)),
            'enrollment_date' => '2026-08-05',
            'status' => 'active',
        ], $enrollmentOverrides));

        return compact('student', 'enrollment');
    }

    /**
     * Assign a fee structure through the real service (so the tests exercise the
     * same server-side amount calculation and duplicate guard as the app).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function assignFeeStructure(
        College $college,
        User $actor,
        StudentEnrollment $enrollment,
        FeeStructure $structure,
        array $overrides = [],
    ): StudentFeeAssignment {
        return $this->withTenant($college, fn () => app(StudentFeeAssignmentService::class)->create($college, array_merge([
            'student_enrollment_id' => $enrollment->id,
            'fee_structure_id' => $structure->id,
            'assigned_at' => '2026-08-10',
            'status' => StudentFeeAssignment::STATUS_ACTIVE,
            'remarks' => 'Test assignment',
        ], $overrides), $actor));
    }

    /**
     * Record a collection through the real service.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function collectFee(
        College $college,
        User $actor,
        StudentFeeAssignment $assignment,
        float $amount,
        array $overrides = [],
    ): FeePayment {
        return $this->withTenant($college, fn () => app(FeeCollectionService::class)->collect($assignment, array_merge([
            'payment_date' => '2026-08-15',
            'payment_mode' => FeePayment::MODE_CASH,
            'amount' => $amount,
            'remarks' => 'Test collection',
        ], $overrides), $actor));
    }

    /**
     * The ledger summary of an assignment, read under the tenant context.
     *
     * @return array<string, mixed>
     */
    private function ledgerOf(College $college, StudentFeeAssignment $assignment): array
    {
        return $this->withTenant(
            $college,
            fn () => app(\App\Domain\Finance\Services\FeeDuesService::class)->summaryFor($assignment->fresh())
        );
    }

    /**
     * The single amount a fee structure's active components add up to.
     */
    private function structureTotal(FeeStructure $structure): float
    {
        return FeeLedger::assignedFromItems($structure->allItems()->get());
    }
}

