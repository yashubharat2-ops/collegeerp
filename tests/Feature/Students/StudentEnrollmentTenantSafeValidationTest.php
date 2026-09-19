<?php

namespace Tests\Feature\Students;

use App\Models\Role;
use App\Models\StudentEnrollment;
use Tests\TestCase;

/**
 * Comprehensive tenant-safe validation and authorization coverage for
 * Student Enrollment, explicitly exercising the 12 scenarios required by the
 * task.
 *
 * A. valid enrollment passes
 * B. cross-college student rejected
 * C. cross-college academic year rejected
 * D. cross-college program rejected
 * E. client college_id cannot override tenant college
 * F. client enrollment_number cannot override generated number
 * G. duplicate active enrollment rejected
 * H. soft-deleted historical enrollment does not block new enrollment
 * I. update existing enrollment does not falsely trigger duplicate validation
 * J. unauthorized user denied
 * K. cross-tenant enrollment access denied
 * L. Super Admin behavior remains consistent with existing tenant-switch authorization rules
 */
class StudentEnrollmentTenantSafeValidationTest extends TestCase
{
    use StudentTestHelpers;

    // A. valid enrollment passes
    public function test_a_valid_enrollment_passes(): void
    {
        $college = $this->makeCollege('TSTA');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college, '2026');
        $program = $this->makeProgram($college, 'BA');

        $this->asCollege($college, $admin)
            ->post(route('student-enrollments.store'), [
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'status' => 'active',
                'enrollment_date' => now()->toDateString(),
                'remarks' => 'Valid enrollment',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('student-enrollments.index'));

        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->assertNotNull($enrollment);
        $this->assertSame($college->id, $enrollment->college_id);
        $this->assertSame($student->id, $enrollment->student_id);
        $this->assertSame($year->id, $enrollment->academic_year_id);
        $this->assertSame($program->id, $enrollment->program_id);
        $this->assertStringStartsWith('ENR-', $enrollment->enrollment_number);
    }

    // B. cross-college student rejected
    public function test_b_cross_college_student_rejected(): void
    {
        $collegeA = $this->makeCollege('TSTB_A');
        $collegeB = $this->makeCollege('TSTB_B');
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_enrollments.create']);
        $studentB = $this->makeStudent($collegeB);
        $yearA = $this->makeYear($collegeA, '2026');

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-enrollments.store'), [
                'student_id' => $studentB->id,
                'academic_year_id' => $yearA->id,
                'status' => 'active',
            ])
            ->assertSessionHasErrors('student_id');

        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $collegeB->id)->count());
    }

    // C. cross-college academic year rejected
    public function test_c_cross_college_academic_year_rejected(): void
    {
        $collegeA = $this->makeCollege('TSTC_A');
        $collegeB = $this->makeCollege('TSTC_B');
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_enrollments.create']);
        $studentA = $this->makeStudent($collegeA);
        $yearB = $this->makeYear($collegeB, '2026');

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-enrollments.store'), [
                'student_id' => $studentA->id,
                'academic_year_id' => $yearB->id,
                'status' => 'active',
            ])
            ->assertSessionHasErrors('academic_year_id');

        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    // D. cross-college program rejected
    public function test_d_cross_college_program_rejected(): void
    {
        $collegeA = $this->makeCollege('TSTD_A');
        $collegeB = $this->makeCollege('TSTD_B');
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_enrollments.create']);
        $studentA = $this->makeStudent($collegeA);
        $yearA = $this->makeYear($collegeA, '2026');
        $programB = $this->makeProgram($collegeB, 'BSC');

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-enrollments.store'), [
                'student_id' => $studentA->id,
                'academic_year_id' => $yearA->id,
                'program_id' => $programB->id,
                'status' => 'active',
            ])
            ->assertSessionHasErrors('program_id');

        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }

    // E. client college_id cannot override tenant college
    public function test_e_client_college_id_cannot_override_tenant_college(): void
    {
        $collegeA = $this->makeCollege('TSTE_A');
        $collegeB = $this->makeCollege('TSTE_B');
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_enrollments.create']);
        $studentA = $this->makeStudent($collegeA);
        $yearA = $this->makeYear($collegeA, '2026');

        $this->asCollege($collegeA, $adminA)
            ->post(route('student-enrollments.store'), [
                'student_id' => $studentA->id,
                'academic_year_id' => $yearA->id,
                'college_id' => $collegeB->id,
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('college_id', $collegeA->id)->first();
        $this->assertNotNull($enrollment);
        $this->assertSame($collegeA->id, $enrollment->college_id);
        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $collegeB->id)->count());
    }

    // F. client enrollment_number cannot override generated number
    public function test_f_client_enrollment_number_cannot_override_generated_number(): void
    {
        $college = $this->makeCollege('TSTF');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.create']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college, '2026');

        $this->asCollege($college, $admin)
            ->post(route('student-enrollments.store'), [
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'enrollment_number' => 'ENR-HACKED-9999',
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->assertNotNull($enrollment);
        $this->assertNotSame('ENR-HACKED-9999', $enrollment->enrollment_number);
        $this->assertStringStartsWith('ENR-', $enrollment->enrollment_number);
        $this->assertDatabaseMissing('student_enrollments', ['enrollment_number' => 'ENR-HACKED-9999']);
    }

    // G. duplicate active enrollment rejected
    public function test_g_duplicate_active_enrollment_rejected(): void
    {
        $college = $this->makeCollege('TSTG');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.create']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college, '2026');
        $program = $this->makeProgram($college, 'BA');

        $payload = [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'status' => 'active',
        ];

        $this->asCollege($college, $admin)->post(route('student-enrollments.store'), $payload)->assertSessionHasNoErrors();
        $this->asCollege($college, $admin)->post(route('student-enrollments.store'), $payload)->assertSessionHasErrors('student_id');

        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());

        // Different academic year is allowed
        $year2 = $this->makeYear($college, '2027');
        $this->asCollege($college, $admin)->post(route('student-enrollments.store'), [
            'student_id' => $student->id,
            'academic_year_id' => $year2->id,
            'program_id' => $program->id,
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        // Different program is allowed
        $program2 = $this->makeProgram($college, 'BSC');
        $this->asCollege($college, $admin)->post(route('student-enrollments.store'), [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'program_id' => $program2->id,
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        // Different student is allowed
        $student2 = $this->makeStudent($college, ['first_name' => 'Other']);
        $this->asCollege($college, $admin)->post(route('student-enrollments.store'), [
            'student_id' => $student2->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $this->assertSame(4, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    // H. soft-deleted historical enrollment does not block new enrollment
    public function test_h_soft_deleted_historical_enrollment_does_not_block_new_enrollment(): void
    {
        $college = $this->makeCollege('TSTH');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college, '2026');
        $program = $this->makeProgram($college, 'BA');

        $payload = [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'status' => 'active',
        ];

        $this->asCollege($college, $admin)->post(route('student-enrollments.store'), $payload)->assertSessionHasNoErrors();
        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->assertNotNull($enrollment);
        $enrollment->delete();
        $this->assertNotNull($enrollment->fresh()->deleted_at);

        $this->asCollege($college, $admin)->post(route('student-enrollments.store'), $payload)->assertSessionHasNoErrors()->assertRedirect(route('student-enrollments.index'));

        $all = StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->get();
        $this->assertCount(2, $all);
        $this->assertSame(1, $all->whereNull('deleted_at')->count());
        $this->assertSame(1, $all->whereNotNull('deleted_at')->count());
    }

    // I. update existing enrollment does not falsely trigger duplicate validation
    public function test_i_update_existing_enrollment_does_not_falsely_trigger_duplicate_validation(): void
    {
        $college = $this->makeCollege('TSTI');
        $admin = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.update']);
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college, '2026');
        $program = $this->makeProgram($college, 'BA');
        $enrollment = $this->makeEnrollment($college, $student, $year, $program, ['enrollment_number' => 'ENR-KEEP-I']);

        // Updating the same enrollment's status/remarks should succeed, not trigger duplicate for itself.
        $this->asCollege($college, $admin)
            ->put(route('student-enrollments.update', $enrollment), [
                'status' => 'completed',
                'remarks' => 'Updated remarks',
            ], ['Referer' => route('student-enrollments.edit', $enrollment)])
            ->assertSessionHas('success');

        $enrollment->refresh();
        $this->assertSame('completed', $enrollment->status);
        $this->assertSame('Updated remarks', $enrollment->remarks);
        $this->assertSame('ENR-KEEP-I', $enrollment->enrollment_number);

        // Reactivating the only live enrollment should succeed and then block a new active duplicate.
        $admin2 = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create', 'student_enrollments.update']);
        // Reset to cancelled then active
        $this->asCollege($college, $admin2)
            ->put(route('student-enrollments.update', $enrollment), ['status' => 'cancelled'], ['Referer' => route('student-enrollments.edit', $enrollment)])
            ->assertSessionHas('success');
        $this->asCollege($college, $admin2)
            ->put(route('student-enrollments.update', $enrollment), ['status' => 'active'], ['Referer' => route('student-enrollments.edit', $enrollment)])
            ->assertSessionHas('success');
        $this->assertSame('active', $enrollment->fresh()->status);

        $this->asCollege($college, $admin2)
            ->post(route('student-enrollments.store'), [
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'status' => 'active',
            ])
            ->assertSessionHasErrors('student_id');
    }

    // J. unauthorized user denied
    public function test_j_unauthorized_user_denied(): void
    {
        $college = $this->makeCollege('TSTJ');
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college, '2026');
        $enrollment = $this->makeEnrollment($college, $student, $year, null, ['enrollment_number' => 'ENR-J-001']);

        $outsider = $this->makeUserWithPermissions($college, []); // no permissions

        $this->asCollege($college, $outsider)->get(route('student-enrollments.index'))->assertForbidden();
        $this->asCollege($college, $outsider)->get(route('student-enrollments.create'))->assertForbidden();
        $this->asCollege($college, $outsider)->post(route('student-enrollments.store'), [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'status' => 'active',
        ])->assertForbidden();
        $this->asCollege($college, $outsider)->get(route('student-enrollments.edit', $enrollment))->assertForbidden();
        $this->asCollege($college, $outsider)->put(route('student-enrollments.update', $enrollment), ['status' => 'cancelled'])->assertForbidden();
        $this->asCollege($college, $outsider)->delete(route('student-enrollments.destroy', $enrollment))->assertForbidden();

        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame('ENR-J-001', $enrollment->fresh()->enrollment_number);
    }

    // K. cross-tenant enrollment access denied
    public function test_k_cross_tenant_enrollment_access_denied(): void
    {
        $collegeA = $this->makeCollege('TSTK_A');
        $collegeB = $this->makeCollege('TSTK_B');
        $studentA = $this->makeStudent($collegeA);
        $studentB = $this->makeStudent($collegeB);
        $yearA = $this->makeYear($collegeA, '2026');
        $yearB = $this->makeYear($collegeB, '2026');
        $enrollmentA = $this->makeEnrollment($collegeA, $studentA, $yearA, null, ['enrollment_number' => 'ENR-K-A']);
        $enrollmentB = $this->makeEnrollment($collegeB, $studentB, $yearB, null, ['enrollment_number' => 'ENR-K-B']);
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_enrollments.view', 'student_enrollments.update', 'student_enrollments.delete']);

        // Index isolated
        $this->asCollege($collegeA, $adminA)->get(route('student-enrollments.index'))->assertSee('ENR-K-A')->assertDontSee('ENR-K-B');

        // Edit/update/delete of other college's enrollment should 404 (CollegeScope)
        $this->asCollege($collegeA, $adminA)->get(route('student-enrollments.edit', $enrollmentB))->assertNotFound();
        $this->asCollege($collegeA, $adminA)
            ->put(route('student-enrollments.update', $enrollmentB), ['status' => 'cancelled'], ['Referer' => route('student-enrollments.index')])
            ->assertNotFound();
        $this->asCollege($collegeA, $adminA)
            ->delete(route('student-enrollments.destroy', $enrollmentB), [], ['Referer' => route('student-enrollments.index')])
            ->assertNotFound();

        $this->assertSame('ENR-K-B', $enrollmentB->fresh()->enrollment_number);
        $this->assertNull($enrollmentB->fresh()->deleted_at);
    }

    // L. Super Admin behavior remains consistent with existing tenant-switch authorization rules
    public function test_l_super_admin_behavior_consistent_with_tenant_switch_rules(): void
    {
        $college = $this->makeCollege('TSTL');
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college, '2026');
        $program = $this->makeProgram($college, 'BA');

        $super = $this->superAdminUser();
        $superRole = Role::firstOrCreate(['college_id' => null, 'slug' => Role::SUPER_ADMIN_SLUG], ['name' => 'Super Admin', 'is_system' => true, 'is_active' => true]);
        $super->roles()->attach($superRole->id, ['college_id' => null]);
        // Super admin must still have a college membership or explicit grant to act; attach as member for this test
        $super->colleges()->attach($college->id, ['is_default' => true]);

        // With explicit college context, super admin can create
        $this->asCollege($college, $super)
            ->post(route('student-enrollments.store'), [
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('student_enrollments', ['college_id' => $college->id, 'student_id' => $student->id]);

        // Super admin with no college context at all is rejected at tenant boundary
        $homeless = $this->superAdminUser();
        $homeless->roles()->attach($superRole->id, ['college_id' => null]);

        $this->actingAs($homeless)->get(route('student-enrollments.index'))->assertForbidden();
        $this->actingAs($homeless)
            ->post(route('student-enrollments.store'), [
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'status' => 'active',
            ])
            ->assertForbidden();

        // Super admin cannot bypass tenant isolation by merely setting active_college_id to a college they are not member of without grant
        $collegeB = $this->makeCollege('TSTL_B');
        $studentB = $this->makeStudent($collegeB);
        $yearB = $this->makeYear($collegeB, '2026');

        $superNoMember = $this->superAdminUser();
        $superNoMember->roles()->attach($superRole->id, ['college_id' => null]);
        // Not a member of collegeB, and no grant key in session, so should be forbidden at ResolveTenant
        $this->actingAs($superNoMember)
            ->withSession(['active_college_id' => $collegeB->id])
            ->post(route('student-enrollments.store'), [
                'student_id' => $studentB->id,
                'academic_year_id' => $yearB->id,
                'status' => 'active',
            ])
            ->assertForbidden();

        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $collegeB->id)->count());
    }
}
