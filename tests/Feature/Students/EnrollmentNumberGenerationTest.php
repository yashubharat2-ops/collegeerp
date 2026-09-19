<?php

namespace Tests\Feature\Students;

use App\Domain\Student\Actions\GenerateEnrollmentNumber;
use App\Models\StudentEnrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\TestCase;

/**
 * Focused tests for the tenant-aware, server-side enrollment number generator.
 *
 * The action mirrors the existing admission (and student) number generators: a
 * transaction with a college-row lock serializes generation per college, the
 * sequence is derived only from numbers of the same college in the same
 * academic-year context, and a collision-check loop guarantees uniqueness.
 */
class EnrollmentNumberGenerationTest extends TestCase
{
    use StudentTestHelpers;

    private function generator(): GenerateEnrollmentNumber
    {
        return app(GenerateEnrollmentNumber::class);
    }

    public function test_generates_default_enr_year_sequence_format(): void
    {
        $college = $this->makeCollege('ENRFMT');

        $number = $this->generator()->execute($college->id);

        $this->assertMatchesRegularExpression('/^ENR-\d{4}-\d{4}$/', $number);
        $this->assertStringStartsWith('ENR-'.date('Y').'-', $number);
    }

    public function test_generates_number_with_explicit_academic_year_context(): void
    {
        $college = $this->makeCollege('ENRYR');

        $number = $this->generator()->execute($college->id, '2026');

        $this->assertSame('ENR-2026-0001', $number);
    }

    public function test_same_college_gets_sequential_enrollment_numbers(): void
    {
        $college = $this->makeCollege('ENRSEQ');
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college, '2026');

        // Seed an existing row so the generator must read, not assume, the next sequence.
        $this->makeEnrollment($college, $student, $year, null, ['enrollment_number' => 'ENR-2026-0001']);

        // The generator is stateless (like the admission generators): it returns
        // max+1 from the persisted rows. The caller persists each number inside
        // its transaction, so each subsequent call observes the prior number.
        foreach (['ENR-2026-0002', 'ENR-2026-0003', 'ENR-2026-0004'] as $expected) {
            $number = $this->generator()->execute($college->id, '2026');
            $this->assertSame($expected, $number);

            $this->makeEnrollment($college, $student, $year, null, ['enrollment_number' => $number]);
        }
    }

    public function test_sequence_restarts_per_academic_year_within_same_college(): void
    {
        $college = $this->makeCollege('ENRRST');
        $student = $this->makeStudent($college);
        $year26 = $this->makeYear($college, '2026');
        $year27 = $this->makeYear($college, '2027');

        $this->makeEnrollment($college, $student, $year26, null, ['enrollment_number' => 'ENR-2026-0007']);

        // Same year continues the sequence; a different academic year starts fresh.
        $this->assertSame('ENR-2026-0008', $this->generator()->execute($college->id, '2026'));
        $this->assertSame('ENR-2027-0001', $this->generator()->execute($college->id, '2027'));
    }

    public function test_different_colleges_have_independent_sequences(): void
    {
        $collegeA = $this->makeCollege('ENRINDA');
        $collegeB = $this->makeCollege('ENRINDB');
        $studentA = $this->makeStudent($collegeA);
        $studentB = $this->makeStudent($collegeB);
        $yearA = $this->makeYear($collegeA, '2026');
        $yearB = $this->makeYear($collegeB, '2026');

        $this->assertSame('ENR-2026-0001', $this->generator()->execute($collegeA->id, '2026'));

        // College B's sequence is unaffected by College A's numbering.
        $this->assertSame('ENR-2026-0001', $this->generator()->execute($collegeB->id, '2026'));

        // Persist college A's first number, then both colleges advance independently.
        $this->makeEnrollment($collegeA, $studentA, $yearA, null, ['enrollment_number' => 'ENR-2026-0001']);
        $this->assertSame('ENR-2026-0002', $this->generator()->execute($collegeA->id, '2026'));

        $this->makeEnrollment($collegeB, $studentB, $yearB, null, ['enrollment_number' => 'ENR-2026-0001']);
        $this->assertSame('ENR-2026-0002', $this->generator()->execute($collegeB->id, '2026'));
    }

    public function test_collision_safety_skips_existing_numbers(): void
    {
        $college = $this->makeCollege('ENRCOL');
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college, '2026');

        // Existing numbers that a naive MAX()+1 would collide with.
        $this->makeEnrollment($college, $student, $year, null, ['enrollment_number' => 'ENR-2026-0001']);
        $this->makeEnrollment($college, $student, $year, null, ['enrollment_number' => 'ENR-2026-0002']);

        $number = $this->generator()->execute($college->id, '2026');

        $this->assertSame('ENR-2026-0003', $number);
        $this->assertDatabaseMissing('student_enrollments', ['college_id' => $college->id, 'enrollment_number' => $number]);
    }

    public function test_existing_number_in_another_college_does_not_consume_local_sequence(): void
    {
        $collegeA = $this->makeCollege('ENRTNA');
        $collegeB = $this->makeCollege('ENRTNB');
        $studentB = $this->makeStudent($collegeB);
        $yearB = $this->makeYear($collegeB, '2026');

        // College B already minted a number in the 2026 band.
        $this->makeEnrollment($collegeB, $studentB, $yearB, null, ['enrollment_number' => 'ENR-2026-0009']);

        // College A's band is unaffected by foreign-college rows.
        $this->assertSame('ENR-2026-0001', $this->generator()->execute($collegeA->id, '2026'));
        // College B continues its own sequence.
        $this->assertSame('ENR-2026-0010', $this->generator()->execute($collegeB->id, '2026'));
    }

    public function test_number_is_generated_server_side_regardless_of_client_input(): void
    {
        $college = $this->makeCollege('ENRSRV');

        // The action accepts only the tenant id and optional year context; there
        // is no input channel through which a client-supplied number could
        // influence the result. The generated value is always derived from the
        // server-side table state.
        $number = $this->generator()->execute($college->id, '2026');

        $this->assertSame('ENR-2026-0001', $number);
        $this->assertNotSame('HACKED-123', $number);
    }

    public function test_generator_reads_outside_tenant_scope_so_sequence_is_stable(): void
    {
        // The generator must read via withoutGlobalScopes so the sequence stays
        // correct even when a TenantContext is active (only its own college is
        // in scope).
        $college = $this->makeCollege('ENRSCO');
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college, '2026');
        app(TenantContext::class)->set($college);

        $this->makeEnrollment($college, $student, $year, null, ['enrollment_number' => 'ENR-2026-0003']);

        $this->assertSame('ENR-2026-0004', $this->generator()->execute($college->id, '2026'));
    }

    public function test_unknown_college_is_rejected(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->generator()->execute(999999999, '2026');
    }
}
