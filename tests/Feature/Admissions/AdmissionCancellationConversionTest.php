<?php

namespace Tests\Feature\Admissions;

use App\Domain\Student\Actions\ConvertAdmissionToStudent;
use App\Domain\Student\Actions\ConvertApplicationToStudent;
use App\Models\Admission;
use App\Models\AdmissionApplication;
use App\Models\College;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * Phase E0: a cancelled admission never produces a student, through EITHER
 * conversion route.
 *
 * Cancelling an admission does not change its AdmissionApplication, which can
 * still read "admitted". The application route used to trust that status alone
 * and could convert the cancelled admission's application. The rule now lives in
 * the conversion actions (AdmissionConversionGuard), so these tests drive the
 * real cancel endpoint and then both conversion routes, and also call the
 * actions directly to prove the service layer refuses without the controller.
 */
class AdmissionCancellationConversionTest extends TestCase
{
    use DepartmentTestHelpers;
    use PhaseDAdmissionFixtures;

    private function operator(College $college): User
    {
        return $this->makeUserWithPermissions($college, [
            'admissions.view',
            'admissions.update',
            'students.view',
            'students.create',
            'student_enrollments.create',
        ]);
    }

    /**
     * An admitted application with an active admission and a program, so both
     * conversion routes receive the same, valid, Student-form payload.
     */
    private function admittedAdmission(College $college): Admission
    {
        $program = $this->phaseDProgram($college);

        return $this->phaseDAdmission($college, ['program_id' => $program->id]);
    }

    /** Cancel through the real endpoint, so the production cancel flow is exercised. */
    private function cancel(College $college, User $user, Admission $admission): void
    {
        $this->asCollege($college, $user)
            ->post(route('admissions.cancel', $admission), ['remarks' => 'Withdrawn'])
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', Admission::withoutGlobalScopes()->find($admission->id)->status);
    }

    private function convertViaApplication(College $college, User $user, AdmissionApplication $application): \Illuminate\Testing\TestResponse
    {
        return $this->asCollege($college, $user)->post(route('students.convert', $application));
    }

    private function convertViaAdmission(College $college, User $user, Admission $admission): \Illuminate\Testing\TestResponse
    {
        return $this->asCollege($college, $user)->post(route('admissions.convert.store', $admission), [
            'first_name' => 'Kiran',
            'status' => 'active',
            'academic_year_id' => $admission->academic_year_id,
            'program_id' => $admission->program_id,
        ]);
    }

    private function studentsIn(College $college): int
    {
        return Student::withoutGlobalScopes()->where('college_id', $college->id)->count();
    }

    private function enrollmentsIn(College $college): int
    {
        return StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count();
    }

    public function test_application_route_refuses_an_admitted_application_whose_admission_was_cancelled(): void
    {
        $college = $this->makeCollege('E0APP');
        $operator = $this->operator($college);
        $admission = $this->admittedAdmission($college);
        $application = $admission->application;

        $this->cancel($college, $operator, $admission);

        // The application still reads "admitted": that is the gap being closed.
        $this->assertSame('admitted', AdmissionApplication::withoutGlobalScopes()->find($application->id)->status);

        $this->convertViaApplication($college, $operator, $application)
            ->assertSessionHasErrors(['application_id' => 'A cancelled admission cannot be converted to a student.']);

        // Nothing written: no student, no enrollment, no conversion audit.
        $this->assertSame(0, $this->studentsIn($college));
        $this->assertSame(0, $this->enrollmentsIn($college));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student.converted', 'college_id' => $college->id]);

        // Historical records are untouched.
        $this->assertSame('admitted', AdmissionApplication::withoutGlobalScopes()->find($application->id)->status);
        $this->assertSame('cancelled', Admission::withoutGlobalScopes()->find($admission->id)->status);
    }

    public function test_admission_route_refuses_a_cancelled_admission_with_no_writes(): void
    {
        $college = $this->makeCollege('E0ADM');
        $operator = $this->operator($college);
        $admission = $this->admittedAdmission($college);

        $this->cancel($college, $operator, $admission);

        $this->convertViaAdmission($college, $operator, $admission)
            ->assertSessionHasErrors(['admission' => 'A cancelled admission cannot be converted to a student.']);

        $this->assertSame(0, $this->studentsIn($college));
        $this->assertSame(0, $this->enrollmentsIn($college));
    }

    public function test_both_routes_refuse_the_same_cancelled_admission_at_the_service_layer(): void
    {
        // No controller and no UI: the conversion actions themselves refuse.
        $college = $this->makeCollege('E0SVC');
        $operator = $this->operator($college);
        $admission = $this->admittedAdmission($college);
        $this->cancel($college, $operator, $admission);

        try {
            app(ConvertApplicationToStudent::class)->execute((int) $admission->application_id, $college->id);
            $this->fail('The application route converted a cancelled admission.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('application_id', $e->errors());
        }

        try {
            app(ConvertAdmissionToStudent::class)->execute(
                (int) $admission->id,
                $college->id,
                ['first_name' => 'Kiran', 'status' => 'active'],
            );
            $this->fail('The admission route converted a cancelled admission.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('admission', $e->errors());
        }

        $this->assertSame(0, $this->studentsIn($college));
        $this->assertSame(0, $this->enrollmentsIn($college));
    }

    public function test_an_active_admission_still_converts_through_the_application_route(): void
    {
        // Positive control: the new rule must not block a normal admitted application.
        $college = $this->makeCollege('E0OK');
        $operator = $this->operator($college);
        $admission = $this->admittedAdmission($college);

        $this->convertViaApplication($college, $operator, $admission->application)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->first();
        $this->assertNotNull($student);
        $this->assertSame((int) $admission->application_id, (int) $student->admission_application_id);
        $this->assertSame(1, $this->enrollmentsIn($college));
    }

    public function test_an_already_converted_application_stays_idempotent_after_its_admission_is_cancelled(): void
    {
        // A converted student that was later soft-deleted no longer blocks the
        // admission's cancellation (existing Phase D rule). Cancelling then
        // re-converting must still be idempotent: the existing student is
        // returned, nothing new is written, and the cancelled-admission refusal
        // does not turn a harmless replay into an error.
        $college = $this->makeCollege('E0IDEM');
        $operator = $this->operator($college);
        $admission = $this->admittedAdmission($college);
        $application = $admission->application;

        $this->convertViaApplication($college, $operator, $application)->assertSessionHasNoErrors();
        $student = Student::withoutGlobalScopes()->where('admission_application_id', $application->id)->firstOrFail();

        Student::withoutGlobalScopes()->whereKey($student->id)->update(['deleted_at' => now()]);
        $this->cancel($college, $operator, $admission);

        $this->convertViaApplication($college, $operator, $application)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'already been converted')
                && str_contains($message, 'No new student was created'));

        $this->assertSame(1, Student::withoutGlobalScopes()->where('admission_application_id', $application->id)->count());
        $this->assertSame(1, $this->enrollmentsIn($college));
    }

    public function test_a_cancelled_admission_in_another_college_never_blocks_conversion(): void
    {
        // The guard is tenant-scoped: a cancelled admission in another college that
        // points at this application id must not block the conversion here.
        $college = $this->makeCollege('E0XA');
        $other = $this->makeCollege('E0XB');
        $operator = $this->operator($college);
        $admission = $this->admittedAdmission($college);

        Admission::withoutGlobalScopes()->create([
            'college_id' => $other->id,
            'academic_year_id' => $admission->academic_year_id,
            'application_id' => $admission->application_id,
            'applicant_id' => $admission->applicant_id,
            'admission_number' => 'ADM-'.Str::upper(Str::random(8)),
            'admission_date' => '2026-07-01',
            'status' => 'cancelled',
        ]);

        $this->convertViaApplication($college, $operator, $admission->application)
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->studentsIn($college));
        $this->assertSame(0, $this->studentsIn($other));
    }
}
