<?php

namespace Tests\Feature\Admissions;

use App\Domain\Admission\Services\AdmissionConversionLock;
use App\Models\Admission;
use App\Models\College;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * Phase D (B): conversion is serialized on the AdmissionApplication row.
 *
 * A true two-connection race cannot be reproduced inside a single PHPUnit
 * process, so these tests pin the guarantees the lock relies on:
 *   - the lock and the existing-student check are tenant-scoped,
 *   - a soft-deleted linked student still blocks re-conversion (no silent
 *     re-admission),
 *   - bulk complete on a converted admission changes only the admission and
 *     never the student, and the cancel guard still holds afterwards.
 */
class AdmissionConversionLockTest extends TestCase
{
    use DepartmentTestHelpers;
    use PhaseDAdmissionFixtures;

    private function bulk(College $college, User $user, string $action, array $ids): TestResponse
    {
        return $this->asCollege($college, $user)->post(route('bulk-actions.execute'), [
            'module' => 'admissions',
            'action' => $action,
            'ids' => $ids,
        ]);
    }

    private function admissionsUser(College $college): User
    {
        return $this->makeUserWithPermissions($college, ['admissions.view', 'admissions.update']);
    }

    public function test_lock_refuses_an_application_from_another_college(): void
    {
        $collegeA = $this->makeCollege('CLKA');
        $collegeB = $this->makeCollege('CLKB');
        $admission = $this->phaseDAdmission($collegeA);

        $lock = app(AdmissionConversionLock::class);

        $this->assertSame(
            $admission->application_id,
            $lock->lockApplication($admission->application_id, $collegeA->id)->id
        );

        $this->expectException(ModelNotFoundException::class);
        $lock->lockApplication($admission->application_id, $collegeB->id);
    }

    public function test_existing_student_lookup_ignores_a_student_linked_from_another_college(): void
    {
        $collegeA = $this->makeCollege('CLKC');
        $collegeB = $this->makeCollege('CLKD');
        $admission = $this->phaseDAdmission($collegeA);

        // A foreign-college row carrying this application id must never be
        // returned to college A's conversion path.
        Student::withoutGlobalScopes()->create([
            'college_id' => $collegeB->id,
            'student_number' => 'STU-FOREIGN',
            'first_name' => 'Foreign',
            'last_name' => 'Row',
            'status' => 'active',
            'admission_application_id' => $admission->application_id,
        ]);

        $this->assertNull(
            app(AdmissionConversionLock::class)->existingStudentFor($admission->application_id, $collegeA->id)
        );
    }

    public function test_soft_deleted_linked_student_still_blocks_reconversion(): void
    {
        $college = $this->makeCollege('CLKE');
        $admission = $this->phaseDAdmission($college);
        $student = $this->phaseDLiveStudentFor($college, $admission->application);
        $student->delete();

        $found = app(AdmissionConversionLock::class)->existingStudentFor($admission->application_id, $college->id);

        $this->assertNotNull($found, 'A soft-deleted linked student must still be found so conversion cannot re-admit silently.');
        $this->assertSame($student->id, $found->id);
    }

    public function test_bulk_complete_on_a_converted_admission_leaves_the_student_untouched(): void
    {
        $college = $this->makeCollege('CLKF');
        $user = $this->admissionsUser($college);
        $admission = $this->phaseDAdmission($college);
        $student = $this->phaseDLiveStudentFor($college, $admission->application);

        $this->bulk($college, $user, 'complete', [$admission->id])->assertSessionHasNoErrors();

        $this->assertSame('completed', Admission::withoutGlobalScopes()->find($admission->id)->status);

        $fresh = Student::withoutGlobalScopes()->find($student->id);
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->deleted_at);
        $this->assertSame('active', $fresh->status);
        $this->assertSame(
            1,
            Student::withoutGlobalScopes()->where('admission_application_id', $admission->application_id)->count(),
            'Completing an admission must never create or remove a student.'
        );
    }

    public function test_cancel_stays_blocked_for_a_completed_admission_with_a_live_student(): void
    {
        $college = $this->makeCollege('CLKG');
        $user = $this->admissionsUser($college);
        $admission = $this->phaseDAdmission($college);
        $this->phaseDLiveStudentFor($college, $admission->application);

        $this->bulk($college, $user, 'complete', [$admission->id])->assertSessionHasNoErrors();
        $this->bulk($college, $user, 'cancel', [$admission->id]);

        $this->assertSame('completed', Admission::withoutGlobalScopes()->find($admission->id)->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'admission.cancelled', 'subject_id' => $admission->id]);
    }
}
