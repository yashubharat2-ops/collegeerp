<?php

namespace Tests\Feature\Students;

use App\Domain\Student\Actions\GenerateStudentNumber;
use App\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\TestCase;

/**
 * Focused tests for the tenant-aware, server-side student number generator.
 *
 * The action mirrors the existing admission number generators: a transaction
 * with a college-row lock serializes generation per college, the sequence is
 * derived only from numbers of the same college in the same year context, and
 * a collision-check loop with a random-suffix fallback guarantees uniqueness.
 */
class StudentNumberGenerationTest extends TestCase
{
    use StudentTestHelpers;

    private function generator(): GenerateStudentNumber
    {
        return app(GenerateStudentNumber::class);
    }

    public function test_generates_default_stu_year_sequence_format(): void
    {
        $college = $this->makeCollege('GNFMT');

        $number = $this->generator()->execute($college->id);

        $this->assertMatchesRegularExpression('/^STU-\d{4}-\d{4}$/', $number);
        $this->assertStringStartsWith('STU-'.date('Y').'-', $number);
    }

    public function test_generates_number_with_explicit_academic_year_context(): void
    {
        $college = $this->makeCollege('GNYR');

        $number = $this->generator()->execute($college->id, '2026');

        $this->assertSame('STU-2026-0001', $number);
    }

    public function test_same_college_gets_sequential_numbers(): void
    {
        $college = $this->makeCollege('GNSEQ');

        // Seed an existing row so the generator must read, not assume, the next sequence.
        Student::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_number' => 'STU-2026-0001',
            'first_name' => 'One',
            'status' => 'active',
        ]);

        // The generator is stateless (like the admission generators): it returns
        // max+1 from the persisted rows. The caller persists each number inside
        // its transaction, so each subsequent call observes the prior number.
        foreach (['STU-2026-0002', 'STU-2026-0003', 'STU-2026-0004'] as $expected) {
            $number = $this->generator()->execute($college->id, '2026');
            $this->assertSame($expected, $number);

            Student::withoutGlobalScopes()->create([
                'college_id' => $college->id,
                'student_number' => $number,
                'first_name' => 'Persisted',
                'status' => 'active',
            ]);
        }
    }

    public function test_sequence_restarts_per_academic_year_within_same_college(): void
    {
        $college = $this->makeCollege('GNRST');

        Student::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_number' => 'STU-2026-0007',
            'first_name' => 'Prev',
            'status' => 'active',
        ]);

        // Different year context → independent sequence.
        $this->assertSame('STU-2026-0008', $this->generator()->execute($college->id, '2026'));
        $this->assertSame('STU-2027-0001', $this->generator()->execute($college->id, '2027'));
    }

    public function test_different_colleges_have_independent_sequences(): void
    {
        $collegeA = $this->makeCollege('GNINDA');
        $collegeB = $this->makeCollege('GNINDB');

        $this->assertSame('STU-2026-0001', $this->generator()->execute($collegeA->id, '2026'));

        // College B's sequence is unaffected by College A's numbering.
        $this->assertSame('STU-2026-0001', $this->generator()->execute($collegeB->id, '2026'));

        // Persist college A's first number, then both colleges advance independently.
        Student::withoutGlobalScopes()->create([
            'college_id' => $collegeA->id,
            'student_number' => 'STU-2026-0001',
            'first_name' => 'A',
            'status' => 'active',
        ]);
        $this->assertSame('STU-2026-0002', $this->generator()->execute($collegeA->id, '2026'));

        Student::withoutGlobalScopes()->create([
            'college_id' => $collegeB->id,
            'student_number' => 'STU-2026-0001',
            'first_name' => 'B',
            'status' => 'active',
        ]);
        $this->assertSame('STU-2026-0002', $this->generator()->execute($collegeB->id, '2026'));
    }

    public function test_collision_safety_skips_existing_numbers(): void
    {
        $college = $this->makeCollege('GNCOL');

        // Existing numbers that a naive MAX()+1 would collide with.
        Student::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_number' => 'STU-2026-0001',
            'first_name' => 'A',
            'status' => 'active',
        ]);
        Student::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_number' => 'STU-2026-0002',
            'first_name' => 'B',
            'status' => 'active',
        ]);

        $number = $this->generator()->execute($college->id, '2026');

        $this->assertSame('STU-2026-0003', $number);
        $this->assertDatabaseMissing('students', ['college_id' => $college->id, 'student_number' => $number]);
    }

    public function test_existing_number_in_another_college_does_not_consume_local_sequence(): void
    {
        $collegeA = $this->makeCollege('GNTNA');
        $collegeB = $this->makeCollege('GNTNB');

        // College B already minted a number in the 2026 band.
        Student::withoutGlobalScopes()->create([
            'college_id' => $collegeB->id,
            'student_number' => 'STU-2026-0009',
            'first_name' => 'B',
            'status' => 'active',
        ]);

        // College A's band is unaffected by foreign-college rows.
        $this->assertSame('STU-2026-0001', $this->generator()->execute($collegeA->id, '2026'));
        // College B continues its own sequence.
        $this->assertSame('STU-2026-0010', $this->generator()->execute($collegeB->id, '2026'));
    }

    public function test_number_is_generated_server_side_regardless_of_client_input(): void
    {
        $college = $this->makeCollege('GNSRV');

        // The action accepts only the tenant id and optional year context; there
        // is no input channel through which a client-supplied number could
        // influence the result. The generated value is always derived from the
        // server-side table state.
        $number = $this->generator()->execute($college->id, '2026');

        $this->assertSame('STU-2026-0001', $number);
        $this->assertNotSame('HACKED-123', $number);
    }

    public function test_generator_reads_outside_tenant_scope_so_sequence_is_stable(): void
    {
        // The generator must read via withoutGlobalScopes so the sequence stays
        // correct even when a TenantContext is active (only its own college is
        // in scope).
        $college = $this->makeCollege('GNSCO');
        app(TenantContext::class)->set($college);

        Student::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_number' => 'STU-2026-0003',
            'first_name' => 'Scoped',
            'status' => 'active',
        ]);

        $this->assertSame('STU-2026-0004', $this->generator()->execute($college->id, '2026'));
    }

    public function test_unknown_college_is_rejected(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->generator()->execute(999999999, '2026');
    }
}
