<?php

namespace Tests\Feature\Admissions;

use App\Models\Admission;
use App\Models\College;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * Phase D (D): an admission cannot be cancelled while a LIVE student exists for
 * its application. The student is never cancelled or deleted automatically.
 */
class AdmissionCancelGuardTest extends TestCase
{
    use DepartmentTestHelpers;
    use PhaseDAdmissionFixtures;

    private const ENDPOINT = 'bulk-actions.execute';

    private function bulkCancel(College $college, User $user, array $ids): TestResponse
    {
        return $this->asCollege($college, $user)->post(route(self::ENDPOINT), [
            'module' => 'admissions',
            'action' => 'cancel',
            'ids' => $ids,
        ]);
    }

    private function adminFor(College $college): User
    {
        return $this->makeUserWithPermissions($college, ['admissions.view', 'admissions.update']);
    }

    public function test_single_cancel_is_blocked_while_a_live_student_exists(): void
    {
        $college = $this->makeCollege('CGSING');
        $admin = $this->adminFor($college);
        $admission = $this->phaseDAdmission($college);
        $student = $this->phaseDLiveStudentFor($college, $admission->application);

        $this->asCollege($college, $admin)
            ->post(route('admissions.cancel', $admission), ['remarks' => 'Withdrawn'])
            ->assertSessionHasErrors('admission');

        $this->assertSame('active', Admission::withoutGlobalScopes()->find($admission->id)->status);
        $liveStudent = Student::withoutGlobalScopes()->find($student->id);
        $this->assertNotNull($liveStudent);
        $this->assertNull($liveStudent->deleted_at);
        $this->assertSame('active', $liveStudent->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'admission.cancelled', 'subject_id' => $admission->id]);
    }

    public function test_bulk_cancel_skips_blocked_admissions_and_cancels_the_rest(): void
    {
        $college = $this->makeCollege('CGBULK');
        $admin = $this->adminFor($college);

        $blocked = $this->phaseDAdmission($college);
        $this->phaseDLiveStudentFor($college, $blocked->application);
        $clean = $this->phaseDAdmission($college);
        $cleanTwo = $this->phaseDAdmission($college);

        $this->bulkCancel($college, $admin, [$blocked->id, $clean->id, $cleanTwo->id])
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, '2 admissions cancelled')
                && str_contains($message, '1 was not cancelled'));

        $this->assertSame('active', Admission::withoutGlobalScopes()->find($blocked->id)->status);
        $this->assertSame('cancelled', Admission::withoutGlobalScopes()->find($clean->id)->status);
        $this->assertSame('cancelled', Admission::withoutGlobalScopes()->find($cleanTwo->id)->status);
    }

    public function test_bulk_cancel_where_every_row_is_blocked_reports_failure_and_changes_nothing(): void
    {
        $college = $this->makeCollege('CGALL');
        $admin = $this->adminFor($college);
        $one = $this->phaseDAdmission($college);
        $two = $this->phaseDAdmission($college);
        $this->phaseDLiveStudentFor($college, $one->application);
        $this->phaseDLiveStudentFor($college, $two->application);

        $this->bulkCancel($college, $admin, [$one->id, $two->id])
            ->assertSessionHasErrors('bulk');

        $this->assertSame('active', Admission::withoutGlobalScopes()->find($one->id)->status);
        $this->assertSame('active', Admission::withoutGlobalScopes()->find($two->id)->status);
        $this->assertSame(2, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_a_soft_deleted_student_does_not_block_cancellation(): void
    {
        $college = $this->makeCollege('CGSOFT');
        $admin = $this->adminFor($college);
        $admission = $this->phaseDAdmission($college);
        $student = $this->phaseDLiveStudentFor($college, $admission->application);
        Student::withoutGlobalScopes()->whereKey($student->id)->update(['deleted_at' => now()]);

        $this->asCollege($college, $admin)
            ->post(route('admissions.cancel', $admission), ['remarks' => 'Withdrawn'])
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', Admission::withoutGlobalScopes()->find($admission->id)->status);
        // The soft-deleted student is left exactly as it was.
        $this->assertNotNull(Student::withoutGlobalScopes()->withTrashed()->find($student->id)->deleted_at);
    }

    public function test_a_student_in_another_college_never_blocks_this_admission(): void
    {
        $college = $this->makeCollege('CGXA');
        $other = $this->makeCollege('CGXB');
        $admin = $this->adminFor($college);
        $admission = $this->phaseDAdmission($college);

        // A student in another college that (artificially) points at this application id
        // must not be counted: the guard is tenant-scoped.
        Student::withoutGlobalScopes()->create([
            'college_id' => $other->id,
            'student_number' => 'STU-OTHER1',
            'first_name' => 'Other',
            'last_name' => 'College',
            'status' => 'active',
            'admission_application_id' => $admission->application_id,
        ]);

        $this->asCollege($college, $admin)
            ->post(route('admissions.cancel', $admission), ['remarks' => 'Withdrawn'])
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', Admission::withoutGlobalScopes()->find($admission->id)->status);
        $this->assertSame(1, DB::table('students')->where('admission_application_id', $admission->application_id)->count());
    }
}
