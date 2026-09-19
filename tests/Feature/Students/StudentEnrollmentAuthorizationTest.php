<?php

namespace Tests\Feature\Students;

use App\Models\StudentEnrollment;
use Tests\TestCase;

/**
 * Focused RBAC coverage for enrollments, mirroring StudentTenancyTest for the
 * Student module: each of the four `student_enrollments.*` permission slugs is
 * required separately, and only the owning college's enrollments are reachable.
 */
class StudentEnrollmentAuthorizationTest extends TestCase
{
    use StudentTestHelpers;

    public function test_each_enrollment_permission_is_required_separately(): void
    {
        $college = $this->makeCollege('ENZAUTH');
        $student = $this->makeStudent($college);
        $year = $this->makeYear($college, '2026');
        $enrollment = $this->makeEnrollment($college, $student, $year, null, ['enrollment_number' => 'ENR-PERM']);

        // A user with only view permission can list, but cannot create, update or delete.
        $viewer = $this->makeUserWithPermissions($college, ['student_enrollments.view']);

        $this->asCollege($college, $viewer)->get(route('student-enrollments.index'))->assertOk();
        $this->asCollege($college, $viewer)->get(route('student-enrollments.create'))->assertForbidden();
        $this->asCollege($college, $viewer)->post(route('student-enrollments.store'), [
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'status' => 'active',
        ])->assertForbidden();
        $this->asCollege($college, $viewer)->get(route('student-enrollments.edit', $enrollment))->assertForbidden();
        $this->asCollege($college, $viewer)->put(route('student-enrollments.update', $enrollment), ['status' => 'cancelled'])->assertForbidden();
        $this->asCollege($college, $viewer)->delete(route('student-enrollments.destroy', $enrollment))->assertForbidden();

        // A user with update but not create cannot store a new enrollment.
        $updater = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.update']);
        $this->asCollege($college, $updater)
            ->post(route('student-enrollments.store'), ['student_id' => $student->id, 'academic_year_id' => $year->id, 'status' => 'active'])
            ->assertForbidden();

        // A user with create but not delete cannot delete.
        $creator = $this->makeUserWithPermissions($college, ['student_enrollments.view', 'student_enrollments.create']);
        $this->asCollege($college, $creator)->delete(route('student-enrollments.destroy', $enrollment))->assertForbidden();

        // Nothing was mutated by the forbidden attempts.
        $this->assertSame(1, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame('ENR-PERM', $enrollment->fresh()->enrollment_number);
        $this->assertSame('active', $enrollment->fresh()->status);
    }

    public function test_user_cannot_create_enrollment_in_another_college_context(): void
    {
        $collegeA = $this->makeCollege('ENZXA');
        $collegeB = $this->makeCollege('ENZXB');
        $studentB = $this->makeStudent($collegeB);
        $yearB = $this->makeYear($collegeB, '2026');

        // The user has the create permission, but only in College A.
        $adminA = $this->makeUserWithPermissions($collegeA, ['student_enrollments.create']);

        // Attempting to act in College B's context is denied at the tenant gate
        // (the user is not a member of College B), so no cross-college row appears.
        $this->actingAs($adminA)
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
