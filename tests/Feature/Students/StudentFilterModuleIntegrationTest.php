<?php

namespace Tests\Feature\Students;

use App\Models\Admission;
use App\Models\AdmissionApplicant;
use App\Models\StudentDocument;
use Tests\TestCase;

/** Canonical filters must mean the same thing outside the main Students list. */
class StudentFilterModuleIntegrationTest extends TestCase
{
    use StudentTestHelpers;

    public function test_enrollment_list_and_export_share_identity_and_academic_status_filters(): void
    {
        $college = $this->makeCollege('SFM01');
        $viewer = $this->makeUserWithPermissions($college, ['student_enrollments.view']);
        $year = $this->makeYear($college, '2026');
        $target = $this->makeStudent($college, ['student_number' => 'REG-SFM01', 'first_name' => 'Alpha', 'status' => 'active']);
        $other = $this->makeStudent($college, ['student_number' => 'REG-SFM02', 'first_name' => 'Beta', 'status' => 'active']);
        $targetEnrollment = $this->makeEnrollment($college, $target, $year, null, ['enrollment_number' => 'ENR-SFM01', 'status' => 'completed']);
        $otherEnrollment = $this->makeEnrollment($college, $other, $year, null, ['enrollment_number' => 'ENR-SFM02', 'status' => 'active']);
        $query = ['student_name' => 'Alpha', 'enrollment_status' => 'completed', 'academic_status' => 'continue'];

        $this->asCollege($college, $viewer)->get(route('student-enrollments.index', $query))
            ->assertOk()->assertSee($targetEnrollment->enrollment_number)->assertDontSee($otherEnrollment->enrollment_number)
            ->assertSee('ACADEMIC STATUS')->assertSee('STUDENT STATUS');

        $csv = $this->asCollege($college, $viewer)->get(route('student-enrollments.export', $query + [
            'ids' => [$targetEnrollment->id, $otherEnrollment->id],
        ]))->assertOk()->streamedContent();
        $this->assertStringContainsString($targetEnrollment->enrollment_number, $csv);
        $this->assertStringNotContainsString($otherEnrollment->enrollment_number, $csv);
    }

    public function test_documents_and_document_pack_reapply_the_student_filter(): void
    {
        $college = $this->makeCollege('SFM02');
        $viewer = $this->makeUserWithPermissions($college, ['student_documents.view', 'students.view']);
        $target = $this->makeStudent($college, ['student_number' => 'REG-DOC01', 'first_name' => 'Alpha']);
        $other = $this->makeStudent($college, ['student_number' => 'REG-DOC02', 'first_name' => 'Beta']);
        $type = $this->makeDocumentType($college);
        foreach ([[$target, 'Alpha document'], [$other, 'Beta document']] as [$student, $title]) {
            StudentDocument::withoutGlobalScopes()->create([
                'college_id' => $college->id,
                'student_id' => $student->id,
                'document_type_id' => $type->id,
                'title' => $title,
                'file_path' => 'students/'.$college->id.'/'.$student->id.'/document.pdf',
                'original_filename' => 'document.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 100,
                'verification_status' => 'pending',
            ]);
        }

        $this->asCollege($college, $viewer)->get(route('student-documents.index', ['student_name' => 'Alpha']))
            ->assertOk()->assertSee('Alpha document')->assertDontSee('Beta document');

        $this->asCollege($college, $viewer)->get(route('student-documents.batch', [
            'student_name' => 'Alpha', 'ids' => [$target->id, $other->id],
        ]))->assertOk()->assertSee('Alpha document')->assertDontSee('Beta document');
    }

    public function test_id_card_list_and_batch_use_canonical_section_context_without_duplicate_controls(): void
    {
        $college = $this->makeCollege('SFM03');
        $viewer = $this->makeUserWithPermissions($college, ['student_id_cards.view', 'student_id_cards.generate', 'students.view']);
        $year = $this->makeYear($college, '2026');
        $program = $this->makeProgram($college, 'BSC');
        $section = $this->makeSection($college, $year, $program, 'A');
        $target = $this->makeStudent($college, ['student_number' => 'REG-CARD01', 'first_name' => 'Alpha']);
        $other = $this->makeStudent($college, ['student_number' => 'REG-CARD02', 'first_name' => 'Beta']);
        $this->makeEnrollment($college, $target, $year, $program, ['section_id' => $section->id]);
        $this->makeEnrollment($college, $other, $year, $program);

        $response = $this->asCollege($college, $viewer)->get(route('student-id-cards.index', [
            'academic_context' => 'section:'.$section->id,
        ]))->assertOk()->assertSee($target->student_number)->assertDontSee($other->student_number);
        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'name="academic_context"'));
        $this->assertStringNotContainsString('name="section_id"', $html);
        $this->assertSame(1, substr_count($html, 'name="program_id"'));

        $this->asCollege($college, $viewer)->get(route('student-id-cards.batch', [
            'academic_context' => 'section:'.$section->id, 'ids' => [$target->id, $other->id],
        ]))->assertOk()->assertSee($target->student_number)->assertDontSee($other->student_number);
    }

    public function test_admission_lists_use_only_supported_canonical_fields_and_keep_workflow_status_separate(): void
    {
        $college = $this->makeCollege('SFM04');
        $viewer = $this->makeUserWithPermissions($college, ['admissions.view', 'admission_applications.view']);
        $year = $this->makeYear($college, '2026');
        $program = $this->makeProgram($college, 'BSC');
        $targetApplication = $this->makeApprovedApplication($college, $year, $program, 'submitted');
        $otherApplication = $this->makeApprovedApplication($college, $year, $program, 'approved');
        $targetApplication->update(['application_number' => 'APP-SFM04-A']);
        $otherApplication->update(['application_number' => 'APP-SFM04-B']);

        $targetApplicant = AdmissionApplicant::withoutGlobalScopes()->findOrFail($targetApplication->applicant_id);
        $otherApplicant = AdmissionApplicant::withoutGlobalScopes()->findOrFail($otherApplication->applicant_id);
        $targetApplicant->update(['first_name' => 'Alpha', 'email' => 'alpha@sfm04.test', 'phone' => '9000000001']);
        $otherApplicant->update(['first_name' => 'Beta', 'email' => 'beta@sfm04.test', 'phone' => '9000000002']);

        $targetAdmission = Admission::withoutGlobalScopes()->create([
            'college_id' => $college->id, 'application_id' => $targetApplication->id,
            'applicant_id' => $targetApplicant->id, 'academic_year_id' => $year->id,
            'program_id' => $program->id, 'admission_number' => 'ADM-SFM04-A',
            'admission_date' => '2026-07-10', 'status' => 'active',
        ]);
        $otherAdmission = Admission::withoutGlobalScopes()->create([
            'college_id' => $college->id, 'application_id' => $otherApplication->id,
            'applicant_id' => $otherApplicant->id, 'academic_year_id' => $year->id,
            'program_id' => $program->id, 'admission_number' => 'ADM-SFM04-B',
            'admission_date' => '2026-08-10', 'status' => 'active',
        ]);

        $applicationQuery = [
            'student_name' => 'Alpha', 'email' => 'alpha@sfm04.test', 'mobile_number' => '9000000001',
            'academic_year_id' => $year->id, 'program_id' => $program->id,
            'status' => 'submitted', 'student_status' => 'inactive',
        ];
        $this->asCollege($college, $viewer)->get(route('admission-applications.index', $applicationQuery))
            ->assertOk()->assertSee('APP-SFM04-A')->assertDontSee('APP-SFM04-B')
            ->assertSee('Admission application status')->assertDontSee('name="student_status"', false)
            ->assertDontSee('name="admission_date_from"', false)
            ->assertSee('data-bulk-param-student-name="Alpha"', false);

        $applicationCsv = $this->asCollege($college, $viewer)->get(route('admission-applications.export', $applicationQuery + [
            'ids' => [$targetApplication->id, $otherApplication->id],
        ]))->assertOk()->streamedContent();
        $this->assertStringContainsString('APP-SFM04-A', $applicationCsv);
        $this->assertStringNotContainsString('APP-SFM04-B', $applicationCsv);

        $bulkExport = $this->asCollege($college, $viewer)->postJson(route('bulk-actions.execute'), [
            'module' => 'admission_applications', 'action' => 'export',
            'ids' => [$targetApplication->id, $otherApplication->id], 'parameters' => $applicationQuery,
        ])->assertOk()->json('data.redirect');
        $this->assertStringContainsString('student_name=Alpha', $bulkExport);
        $bulkCsv = $this->asCollege($college, $viewer)->get($bulkExport)->streamedContent();
        $this->assertStringContainsString('APP-SFM04-A', $bulkCsv);
        $this->assertStringNotContainsString('APP-SFM04-B', $bulkCsv);

        $admissionQuery = [
            'student_name' => 'Alpha', 'admission_date_from' => '2026-07-01',
            'admission_date_to' => '2026-07-31', 'status' => 'active', 'student_status' => 'inactive',
        ];
        $this->asCollege($college, $viewer)->get(route('admissions.index', $admissionQuery))
            ->assertOk()->assertSee('ADM-SFM04-A')->assertDontSee('ADM-SFM04-B')
            ->assertSee('Admission status')->assertSee('name="admission_date_from"', false)
            ->assertDontSee('name="student_status"', false);

        $admissionCsv = $this->asCollege($college, $viewer)->get(route('admissions.export', $admissionQuery + [
            'ids' => [$targetAdmission->id, $otherAdmission->id],
        ]))->assertOk()->streamedContent();
        $this->assertStringContainsString('ADM-SFM04-A', $admissionCsv);
        $this->assertStringNotContainsString('ADM-SFM04-B', $admissionCsv);
    }
}
