<?php

namespace Tests\Feature\Students;

use App\Models\AdmissionDocumentType;
use App\Models\ApprovalRequest;
use App\Models\College;
use App\Models\GeoLocation;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentEnrollment;
use App\Models\StudentQualification;
use App\Models\StudentRegistrationDraft;
use App\Models\StudentRegistrationDraftDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The five-step registration wizard.
 *
 * These tests exercise the behaviour the wizard promises: step navigation and
 * per-step validation, draft persistence without any student row, the dependent
 * address chain, guardian auto-fill, multiple qualifications with marks
 * arithmetic, course-wise document requirements, submission through the existing
 * approval framework, duplicate-submission prevention and tenant isolation.
 */
class RegistrationWizardTest extends TestCase
{
    use StudentTestHelpers;

    private function staff(College $college): User
    {
        return $this->makeUserWithPermissions($college, ['students.view', 'students.create']);
    }

    private function saveStep(College $college, User $user, int $step, array $data, bool $draft = false)
    {
        $route = $draft
            ? route('students.registration.step.draft', ['step' => $step])
            : route('students.registration.step.save', ['step' => $step]);

        return $this->asCollege($college, $user)->post($route, $data);
    }

    private function draftFor(College $college, User $user): StudentRegistrationDraft
    {
        return StudentRegistrationDraft::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->where('user_id', $user->id)
            ->where('status', StudentRegistrationDraft::STATUS_DRAFT)
            ->latest('id')
            ->firstOrFail();
    }

    /** Fill steps 1-4 with valid minimal data and return the draft. */
    private function completeDraft(College $college, User $user, array $stepOne = [], array $stepThree = []): StudentRegistrationDraft
    {
        $this->saveStep($college, $user, 1, array_merge([
            'first_name' => 'Riya',
            'last_name' => 'Verma',
            'gender' => 'female',
            'date_of_birth' => '2008-06-15',
            'phone' => '9111111111',
        ], $stepOne))->assertRedirect(route('students.registration.step', ['step' => 2]));

        $this->saveStep($college, $user, 2, [
            'father_name' => 'Suresh Verma',
            'father_phone' => '9222222222',
            'mother_name' => 'Kavita Verma',
            'guardian_relation' => 'father',
        ])->assertRedirect(route('students.registration.step', ['step' => 3]));

        $this->saveStep($college, $user, 3, array_merge([
            'academic_year_id' => $stepThree['academic_year_id'] ?? null,
            'program_id' => $stepThree['program_id'] ?? null,
            'semester' => '1',
            'admission_date' => '2026-06-01',
            'enrollment_date' => '2026-06-02',
        ], $stepThree))->assertRedirect(route('students.registration.step', ['step' => 4]));

        return $this->draftFor($college, $user);
    }

    // -----------------------------------------------------------------
    // Navigation + step validation
    // -----------------------------------------------------------------

    public function test_registration_entry_point_starts_the_wizard_and_shows_the_five_steps(): void
    {
        $college = $this->makeCollege('WIZ1');
        $user = $this->staff($college);

        $this->asCollege($college, $user)
            ->get(route('students.registration.create'))
            ->assertRedirect(route('students.registration.step', ['step' => 1]));

        $this->asCollege($college, $user)
            ->get(route('students.registration.step', ['step' => 1]))
            ->assertOk()
            ->assertSee('Student Registration')
            ->assertSee('Step 1 of 5')
            ->assertSee('Basic Information')
            ->assertSee('Parent/Guardian')
            ->assertSee('Academic Record')
            ->assertSee('Attach Documents')
            ->assertSee('Final Review');

        $this->assertSame(1, StudentRegistrationDraft::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->where('user_id', $user->id)
            ->count());
    }

    public function test_a_fresh_draft_is_not_a_student_and_saves_nothing_else(): void
    {
        $college = $this->makeCollege('WIZ2');
        $user = $this->staff($college);

        $this->saveStep($college, $user, 1, ['first_name' => 'Riya'], draft: true)
            ->assertRedirect(route('students.registration.step', ['step' => 1]));

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(0, ApprovalRequest::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_draft_survives_leaving_the_wizard_and_resumes_on_the_current_step(): void
    {
        $college = $this->makeCollege('WIZ3');
        $user = $this->staff($college);

        $this->saveStep($college, $user, 1, ['first_name' => 'Aarav', 'last_name' => 'Shah'], draft: true)->assertRedirect();

        // A new request (a later session) resumes the same draft on the same step.
        $this->asCollege($college, $user)->get(route('students.registration.step', ['step' => 1]))
            ->assertOk()
            ->assertSee('Aarav')
            ->assertSee('Shah');

        $this->asCollege($college, $user)->get(route('students.registration.create'))
            ->assertRedirect(route('students.registration.step', ['step' => 1]));
    }

    public function test_a_failed_step_returns_to_that_step_with_field_errors(): void
    {
        $college = $this->makeCollege('WIZ4');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $this->saveStep($college, $user, 1, ['first_name' => 'Riya'])->assertRedirect(route('students.registration.step', ['step' => 2]));

        // Step 3 without the required academic context.
        $this->saveStep($college, $user, 3, ['semester' => '1'])
            ->assertRedirect(route('students.registration.step', ['step' => 3]))
            ->assertSessionHasErrors(['academic_year_id', 'program_id']);

        $this->saveStep($college, $user, 3, [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
        ])->assertRedirect(route('students.registration.step', ['step' => 4]));
    }

    public function test_step_one_rejects_a_foreign_college_programme_and_section(): void
    {
        $college = $this->makeCollege('WIZ5');
        $other = $this->makeCollege('WIZ5B');
        $user = $this->staff($college);
        $otherYear = $this->makeYear($other);
        $otherProgram = $this->makeProgram($other);

        $this->saveStep($college, $user, 3, [
            'academic_year_id' => $otherYear->id,
            'program_id' => $otherProgram->id,
        ])->assertSessionHasErrors(['academic_year_id', 'program_id']);
    }

    // -----------------------------------------------------------------
    // Address cascade
    // -----------------------------------------------------------------

    public function test_the_address_chain_is_validated_server_side_and_names_come_from_the_directory(): void
    {
        $college = $this->makeCollege('WIZ6');
        $user = $this->staff($college);

        $country = GeoLocation::create(['level' => 'country', 'name' => 'India', 'is_active' => true]);
        $state = GeoLocation::create(['level' => 'state', 'name' => 'Madhya Pradesh', 'parent_id' => $country->id]);
        $district = GeoLocation::create(['level' => 'district', 'name' => 'Bhopal', 'parent_id' => $state->id]);
        $otherDistrict = GeoLocation::create(['level' => 'district', 'name' => 'Indore', 'parent_id' => $state->id]);
        $block = GeoLocation::create(['level' => 'block', 'name' => 'Berasia', 'parent_id' => $district->id]);
        $village = GeoLocation::create(['level' => 'village', 'name' => 'Barkheda', 'parent_id' => $block->id]);

        // A village whose block does not match the submitted block is refused.
        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'country_id' => $country->id,
            'state_id' => $state->id,
            'district_id' => $otherDistrict->id,
            'block_id' => $block->id,
            'village_id' => $village->id,
        ])->assertSessionHasErrors(['block_id']);

        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'country_id' => $country->id,
            'state_id' => $state->id,
            'district_id' => $district->id,
            'block_id' => $block->id,
            'village_id' => $village->id,
        ])->assertRedirect(route('students.registration.step', ['step' => 2]));

        $step = $this->draftFor($college, $user)->stepData(1);
        $this->assertSame('India', $step['country']);
        $this->assertSame('Madhya Pradesh', $step['state']);
        $this->assertSame('Bhopal', $step['district']);
        $this->assertSame('Berasia', $step['block_tehsil']);
        $this->assertSame('Barkheda', $step['city']);
    }

    public function test_same_as_permanent_address_stores_one_copy_and_never_duplicates_it(): void
    {
        $college = $this->makeCollege('WIZ7');
        $user = $this->staff($college);

        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'address_line_1' => '12 MG Road',
            'city' => 'Bhopal',
            'state' => 'Madhya Pradesh',
            'postal_code' => '462001',
            'correspondence_same_as_permanent' => '1',
            'correspondence_address_line_1' => 'Somewhere else',
        ])->assertRedirect();

        $step = $this->draftFor($college, $user)->stepData(1);
        $this->assertTrue($step['correspondence_same_as_permanent']);
        $this->assertNull($step['correspondence_address_line_1']);

        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'address_line_1' => '12 MG Road',
            'correspondence_same_as_permanent' => '0',
            'correspondence_address_line_1' => 'Hostel Block C',
        ])->assertRedirect();

        $step = $this->draftFor($college, $user)->stepData(1);
        $this->assertFalse($step['correspondence_same_as_permanent']);
        $this->assertSame('Hostel Block C', $step['correspondence_address_line_1']);
    }

    // -----------------------------------------------------------------
    // Guardian auto-fill
    // -----------------------------------------------------------------

    public function test_guardian_details_are_derived_from_the_chosen_person_on_the_server(): void
    {
        $college = $this->makeCollege('WIZ8');
        $user = $this->staff($college);

        $this->saveStep($college, $user, 1, ['first_name' => 'Riya', 'middle_name' => 'R', 'last_name' => 'Verma', 'phone' => '9111111111'])->assertRedirect();

        $this->saveStep($college, $user, 2, [
            'father_name' => 'Suresh Verma',
            'father_phone' => '9222222222',
            'father_email' => 'suresh@example.test',
            'father_occupation' => 'Farmer',
            'guardian_relation' => 'father',
            // A tampered value must not survive: the server rebuilds it.
            'guardian_name' => 'Someone Else',
            'guardian_phone' => '9000000000',
        ])->assertRedirect();

        $step = $this->draftFor($college, $user)->stepData(2);
        $this->assertSame('Suresh Verma', $step['guardian_name']);
        $this->assertSame('9222222222', $step['guardian_phone']);
        $this->assertSame('suresh@example.test', $step['guardian_email']);
        $this->assertSame('Farmer', $step['guardian_occupation']);

        // Switching to the mother re-derives from the mother's record.
        $this->saveStep($college, $user, 2, [
            'father_name' => 'Suresh Verma',
            'mother_name' => 'Kavita Verma',
            'mother_phone' => '9333333333',
            'guardian_relation' => 'mother',
        ])->assertRedirect();

        $step = $this->draftFor($college, $user)->stepData(2);
        $this->assertSame('Kavita Verma', $step['guardian_name']);
        $this->assertSame('9333333333', $step['guardian_phone']);
    }

    public function test_switching_to_other_preserves_the_details_that_were_on_screen(): void
    {
        $college = $this->makeCollege('WIZ9');
        $user = $this->staff($college);

        $this->saveStep($college, $user, 1, ['first_name' => 'Riya'])->assertRedirect();

        $this->saveStep($college, $user, 2, [
            'father_name' => 'Suresh Verma',
            'father_phone' => '9222222222',
            'guardian_relation' => 'father',
        ])->assertRedirect();

        // The browser submits the previously auto-filled values when the user
        // switches to "Other"; the server keeps them as independent data.
        $this->saveStep($college, $user, 2, [
            'father_name' => 'Suresh Verma',
            'father_phone' => '9222222222',
            'guardian_relation' => 'other',
            'guardian_name' => 'Suresh Verma',
            'guardian_phone' => '9222222222',
            'guardian_occupation' => 'Guardian',
        ])->assertRedirect();

        $step = $this->draftFor($college, $user)->stepData(2);
        $this->assertSame('other', $step['guardian_relation']);
        $this->assertSame('Suresh Verma', $step['guardian_name']);
        $this->assertSame('Guardian', $step['guardian_occupation']);
    }

    public function test_self_guardian_uses_the_students_own_details(): void
    {
        $college = $this->makeCollege('WIZ10');
        $user = $this->staff($college);

        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya', 'last_name' => 'Verma',
            'phone' => '9111111111', 'email' => 'riya@example.test',
        ])->assertRedirect();

        $this->saveStep($college, $user, 2, ['guardian_relation' => 'self'])->assertRedirect();

        $step = $this->draftFor($college, $user)->stepData(2);
        $this->assertSame('Riya Verma', $step['guardian_name']);
        $this->assertSame('9111111111', $step['guardian_phone']);
        $this->assertSame('riya@example.test', $step['guardian_email']);
        $this->assertSame('Student', $step['guardian_occupation']);
    }

    // -----------------------------------------------------------------
    // Qualifications
    // -----------------------------------------------------------------

    public function test_multiple_qualifications_are_stored_and_percentage_is_derived_only_from_marks(): void
    {
        Storage::fake('private');
        $college = $this->makeCollege('WIZ11');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $this->completeDraft($college, $user, [], [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
        ]);

        $this->saveStep($college, $user, 3, [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'qualifications' => [
                [
                    'level' => 'class_10',
                    'qualification' => 'High School',
                    'examination_year' => 2022,
                    'board_university' => 'MPBSE',
                    'marks_obtained' => 430,
                    'max_marks' => 500,
                    'subjects_text' => "Mathematics | 100 | 88\nScience | 100 | 82",
                ],
                [
                    'level' => 'class_12',
                    'qualification' => 'Higher Secondary',
                    'examination_year' => 2024,
                    'board_university' => 'MPBSE',
                    'grade' => 'A1',
                    // A percentage supplied with a grade must be discarded.
                    'percentage' => 91.5,
                ],
            ],
        ])->assertRedirect();

        $rows = $this->draftFor($college, $user)->stepData(3)['qualifications'];
        $this->assertCount(2, $rows);
        $this->assertSame('class_10', $rows[0]['level']);

        // Submit and check what was persisted.
        $this->asCollege($college, $user)->post(route('students.registration.submit'), [
            'draft_id' => $this->draftFor($college, $user)->id,
            'confirm_declaration' => '1',
        ])->assertRedirect(route('students.index'));

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $saved = StudentQualification::withoutGlobalScopes()
            ->where('student_id', $student->id)
            ->orderBy('examination_year')
            ->get();

        $this->assertCount(2, $saved);
        $this->assertEquals('86.00', $saved[0]->percentage);
        $this->assertCount(2, $saved[0]->subjects);
        $this->assertSame('Mathematics', $saved[0]->subjects[0]['name']);
        $this->assertEquals(88, $saved[0]->subjects[0]['marks_obtained']);

        // The grade-based result keeps its grade and no derived percentage.
        $this->assertSame('A1', $saved[1]->grade);
        $this->assertNull($saved[1]->percentage);
    }

    public function test_removing_a_qualification_row_deletes_it_from_the_draft(): void
    {
        $college = $this->makeCollege('WIZ12');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $this->saveStep($college, $user, 3, [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'qualifications' => [
                ['level' => 'class_10', 'examination_year' => 2022],
                ['level' => 'class_12', 'examination_year' => 2024],
            ],
        ])->assertRedirect();

        $this->saveStep($college, $user, 3, [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'qualifications' => [
                ['level' => 'class_10', 'examination_year' => 2022],
                ['level' => 'class_12', 'examination_year' => 2024, 'remove' => '1'],
            ],
        ])->assertRedirect();

        $rows = $this->draftFor($college, $user)->stepData(3)['qualifications'];
        $this->assertCount(1, $rows);
        $this->assertSame('class_10', $rows[0]['level']);
    }

    // -----------------------------------------------------------------
    // Documents
    // -----------------------------------------------------------------

    public function test_required_course_documents_block_submission_and_uploading_never_verifies(): void
    {
        Storage::fake('private');
        $college = $this->makeCollege('WIZ13');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $required = $this->makeDocumentType($college, 'TRANSFER');
        $required->programs()->attach($program->id, [
            'college_id' => $college->id, 'is_required' => true, 'can_submit_later' => false,
        ]);

        $deferred = $this->makeDocumentType($college, 'MIGRATION');
        $deferred->programs()->attach($program->id, [
            'college_id' => $college->id, 'is_required' => true, 'can_submit_later' => true,
        ]);

        $draft = $this->completeDraft($college, $user, [], [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
        ]);

        // Missing required document: submission is sent back to step 4.
        $this->asCollege($college, $user)->post(route('students.registration.submit'), [
            'draft_id' => $draft->id,
            'confirm_declaration' => '1',
        ])->assertRedirect(route('students.registration.step', ['step' => 4]))
            ->assertSessionHasErrors(['documents']);

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());

        // Attach the required document.
        $this->asCollege($college, $user)->post(route('students.registration.documents.upload'), [
            'draft_id' => $draft->id,
            'document_type_id' => $required->id,
            'file' => UploadedFile::fake()->create('transfer.pdf', 20, 'application/pdf'),
        ])->assertRedirect(route('students.registration.step', ['step' => 4]));

        // The deferred document is NOT required at submission time.
        $this->asCollege($college, $user)->post(route('students.registration.submit'), [
            'draft_id' => $draft->id,
            'confirm_declaration' => '1',
        ])->assertRedirect(route('students.index'));

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $document = StudentDocument::withoutGlobalScopes()->where('student_id', $student->id)->firstOrFail();

        $this->assertSame($required->id, $document->document_type_id);
        $this->assertSame('pending', $document->verification_status);
        $this->assertSame(0, StudentRegistrationDraftDocument::withoutGlobalScopes()->where('draft_id', $draft->id)->count());
    }

    public function test_a_document_type_from_another_college_is_rejected(): void
    {
        Storage::fake('private');
        $college = $this->makeCollege('WIZ14');
        $other = $this->makeCollege('WIZ14B');
        $user = $this->staff($college);
        $foreignType = $this->makeDocumentType($other, 'FOREIGN');

        $this->saveStep($college, $user, 1, ['first_name' => 'Riya'])->assertRedirect();
        $draft = $this->draftFor($college, $user);

        $this->asCollege($college, $user)->post(route('students.registration.documents.upload'), [
            'draft_id' => $draft->id,
            'document_type_id' => $foreignType->id,
            'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors(['document_type_id']);

        $this->assertSame(0, StudentRegistrationDraftDocument::withoutGlobalScopes()->where('draft_id', $draft->id)->count());
    }

    // -----------------------------------------------------------------
    // Submission
    // -----------------------------------------------------------------

    public function test_submission_creates_one_pending_student_and_one_approval_request_without_an_enrollment(): void
    {
        $college = $this->makeCollege('WIZ15');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $draft = $this->completeDraft($college, $user, [], [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
        ]);

        $this->asCollege($college, $user)->post(route('students.registration.submit'), [
            'draft_id' => $draft->id,
            'confirm_declaration' => '1',
            'remarks' => 'Please review the transfer certificate.',
        ])->assertRedirect(route('students.index'))->assertSessionHas('success');

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->sole();

        $this->assertSame('inactive', $student->status);
        $this->assertSame(Student::REGISTRATION_PENDING, $student->registration_status);
        $this->assertSame('Riya', $student->first_name);
        $this->assertSame('9222222222', $student->father_phone);
        $this->assertSame('Suresh Verma', $student->guardian_name);
        $this->assertSame('Please review the transfer certificate.', $student->remarks);
        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->count());

        $request = ApprovalRequest::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->where('module', 'student')
            ->where('action', 'registration_approval')
            ->sole();

        $this->assertSame('pending', $request->status);
        $this->assertSame($student->id, (int) $request->target_id);
        $this->assertSame($year->id, (int) $request->payload['academic_year_id']);
        $this->assertSame($program->id, (int) $request->payload['program_id']);
        $this->assertSame('1', (string) $request->payload['semester']);

        $this->assertTrue($draft->refresh()->isSubmitted());
    }

    public function test_a_second_submit_of_the_same_draft_creates_no_duplicate_records(): void
    {
        $college = $this->makeCollege('WIZ16');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $draft = $this->completeDraft($college, $user, [], [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
        ]);

        $payload = ['draft_id' => $draft->id, 'confirm_declaration' => '1'];

        $this->asCollege($college, $user)->post(route('students.registration.submit'), $payload)
            ->assertRedirect(route('students.index'));

        $this->asCollege($college, $user)->post(route('students.registration.submit'), $payload)
            ->assertRedirect(route('students.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(1, ApprovalRequest::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->where('action', 'registration_approval')
            ->count());
    }

    public function test_submission_without_the_declaration_is_refused(): void
    {
        $college = $this->makeCollege('WIZ17');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $draft = $this->completeDraft($college, $user, [], [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
        ]);

        $this->asCollege($college, $user)->post(route('students.registration.submit'), [
            'draft_id' => $draft->id,
        ])->assertSessionHasErrors(['confirm_declaration']);

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_approval_activates_the_student_and_creates_the_enrollment_context(): void
    {
        $college = $this->makeCollege('WIZ18');
        $submitter = $this->staff($college);
        $approver = $this->makeUserWithPermissions($college, ['students.view', 'students.create', 'approvals.view', 'approvals.approve']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $draft = $this->completeDraft($college, $submitter, [], [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
        ]);

        $this->asCollege($college, $submitter)->post(route('students.registration.submit'), [
            'draft_id' => $draft->id,
            'confirm_declaration' => '1',
        ])->assertRedirect(route('students.index'));

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->sole();
        $request = ApprovalRequest::withoutGlobalScopes()->where('target_id', $student->id)->sole();

        $this->asCollege($college, $approver)->post(route('approvals.approve', $request), [])
            ->assertRedirect();

        $student->refresh();
        $this->assertSame('active', $student->status);
        $this->assertSame(Student::REGISTRATION_APPROVED, $student->registration_status);

        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->sole();
        $this->assertSame($year->id, (int) $enrollment->academic_year_id);
        $this->assertSame($program->id, (int) $enrollment->program_id);
        $this->assertSame('1', $enrollment->semester);
    }

    // -----------------------------------------------------------------
    // Tenancy + authorization
    // -----------------------------------------------------------------

    public function test_a_draft_belongs_to_one_user_and_one_college(): void
    {
        $college = $this->makeCollege('WIZ19');
        $other = $this->makeCollege('WIZ19B');
        $owner = $this->staff($college);
        $intruder = $this->staff($other);

        $this->saveStep($college, $owner, 1, ['first_name' => 'Riya'])->assertRedirect();
        $draft = $this->draftFor($college, $owner);

        // Another college's user cannot read the draft they do not own.
        $this->asCollege($other, $intruder)
            ->get(route('students.registration.step', ['step' => 1]))
            ->assertRedirect(route('students.registration.create'));

        // Nor act on it by posting its id.
        $this->asCollege($other, $intruder)
            ->post(route('students.registration.step.save', ['step' => 2]), ['draft_id' => $draft->id, 'father_name' => 'X'])
            ->assertNotFound();

        $this->assertNull($draft->fresh()->stepData(2)['father_name'] ?? null);
    }

    public function test_a_user_without_students_create_permission_cannot_open_the_wizard(): void
    {
        $college = $this->makeCollege('WIZ20');
        $viewer = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $viewer)
            ->get(route('students.registration.step', ['step' => 1]))
            ->assertForbidden();
    }

    public function test_the_wizard_stores_the_aadhaar_encrypted_and_never_in_clear_text(): void
    {
        $college = $this->makeCollege('WIZ21');
        $user = $this->staff($college);

        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'aadhaar_number' => '9999 4105 7058',
        ])->assertRedirect();

        $draft = $this->draftFor($college, $user);
        $step = $draft->stepData(1);

        $this->assertArrayNotHasKey('aadhaar_number', $step);
        $this->assertNotSame('999941057058', $step['aadhaar_encrypted']);
        $this->assertSame('7058', $step['aadhaar_last4']);
        $this->assertStringNotContainsString('999941057058', json_encode($draft->payload));

        // Saving the step again with a blank field keeps the stored number.
        $this->saveStep($college, $user, 1, ['first_name' => 'Riya', 'aadhaar_number' => ''])->assertRedirect();
        $this->assertNotEmpty($this->draftFor($college, $user)->stepData(1)['aadhaar_encrypted']);
    }

    public function test_an_approved_students_phone_blocks_a_new_registration(): void
    {
        $college = $this->makeCollege('WIZ22');
        $user = $this->staff($college);
        $this->makeStudent($college, ['phone' => '9555555555']);

        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'phone' => '9555555555',
        ])->assertSessionHasErrors(['phone']);

        // No student, qualification or approval was created. The empty draft row
        // that the failed attempt opened is reused on the next try.
        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame([], $this->draftFor($college, $user)->stepData(1));
    }
}
