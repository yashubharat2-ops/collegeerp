<?php

namespace Tests\Feature\Admissions;

use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\AdmissionEnquiry;
use App\Models\College;
use App\Models\Program;
use App\Models\Student;
use Illuminate\Support\Str;

/**
 * Fixture builders shared by the Phase D admission tests. They write through
 * withoutGlobalScopes() so each fixture is explicitly tied to its college, and
 * they never bypass the application code under test.
 */
trait PhaseDAdmissionFixtures
{
    private function phaseDYear(College $college): AcademicYear
    {
        return AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => '2026-27',
            'code' => 'Y'.Str::upper(Str::random(6)),
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);
    }

    private function phaseDProgram(College $college): Program
    {
        return Program::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => 'BSc',
            'code' => 'P'.Str::upper(Str::random(5)),
            'status' => 'active',
        ]);
    }

    private function phaseDApplicant(College $college, array $overrides = []): AdmissionApplicant
    {
        return AdmissionApplicant::withoutGlobalScopes()->create(array_merge([
            'college_id' => $college->id,
            'first_name' => 'Admit',
            'last_name' => 'Candidate',
            'email' => strtolower($college->code).'-'.Str::lower(Str::random(8)).'@example.test',
            'phone' => '9000000001',
            'gender' => 'female',
            'status' => 'active',
        ], $overrides));
    }

    private function phaseDEnquiry(College $college, AdmissionApplicant $applicant, array $overrides = []): AdmissionEnquiry
    {
        return AdmissionEnquiry::withoutGlobalScopes()->create(array_merge([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'enquiry_number' => 'ENQ-'.Str::upper(Str::random(8)),
            'status' => 'new',
            'source' => 'Website',
        ], $overrides));
    }

    private function phaseDApplication(College $college, AdmissionApplicant $applicant, string $status = 'under_review', array $overrides = []): AdmissionApplication
    {
        return AdmissionApplication::withoutGlobalScopes()->create(array_merge([
            'college_id' => $college->id,
            'applicant_id' => $applicant->id,
            'application_number' => 'APP-'.Str::upper(Str::random(8)),
            'status' => $status,
        ], $overrides));
    }

    /**
     * An admission whose application is 'admitted' (the state a real admission
     * reaches), linked to a fresh applicant.
     */
    private function phaseDAdmission(College $college, array $overrides = []): Admission
    {
        $applicant = $this->phaseDApplicant($college);
        $year = $this->phaseDYear($college);
        $application = $this->phaseDApplication($college, $applicant, 'admitted', ['academic_year_id' => $year->id]);

        $admission = Admission::withoutGlobalScopes()->create(array_merge([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'admission_number' => 'ADM-'.Str::upper(Str::random(8)),
            'admission_date' => '2026-07-01',
            'status' => 'active',
        ], $overrides));

        // `Admission::application()` is tenant-scoped (CollegeScope fails closed
        // outside a request), so a lazy load in a test returns null. Attach the
        // application this fixture just created, which is in the same college.
        return $admission->setRelation('application', $application);
    }

    /**
     * A live (not soft-deleted) student linked to an application, as the
     * conversion routes create it.
     */
    private function phaseDLiveStudentFor(College $college, AdmissionApplication $application): Student
    {
        return Student::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_number' => 'STU-'.Str::upper(Str::random(6)),
            'first_name' => 'Linked',
            'last_name' => 'Student',
            'status' => 'active',
            'admission_application_id' => $application->id,
        ]);
    }
}
