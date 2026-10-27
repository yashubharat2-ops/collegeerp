<?php

namespace Tests\Feature\Students;

use App\Domain\Student\Services\StudentIdentifierService;
use App\Domain\Student\Services\StudentService;
use App\Domain\Student\Support\RegistrationDraftUpgrade;
use App\Domain\Student\Support\RegistrationStepRules;
use App\Models\Admission;
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
use App\Support\Tenancy\TenantContext;
use Database\Seeders\GeoLocationSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The four-step registration wizard.
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

    /** Smallest valid PNG, used to prove a blocked upload never reaches disk. */
    private function onePixelPng(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        );
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

    /**
     * Save step 1 with the personal AND parent/guardian blocks.
     *
     * Parent/Guardian is no longer a step of its own — it is sections C and D of
     * Basic Information — so a "basic" save has to carry both halves, exactly as
     * the merged page posts them.
     */
    private function saveBasic(College $college, User $user, array $data = [], bool $draft = false)
    {
        return $this->saveStep($college, $user, 1, array_merge([
            'first_name' => 'Riya',
            'last_name' => 'Verma',
            'date_of_birth' => '2008-06-15',
            'phone' => '9111111111',
            'email' => 'riya@example.test',
            'father_name' => 'Suresh Verma',
            'father_phone' => '9222222222',
            'mother_name' => 'Kavita Verma',
            'guardian_relation' => 'father',
        ], $data), $draft);
    }

    /** Fill steps 1-3 with valid minimal data and return the draft. */
    private function completeDraft(College $college, User $user, array $stepOne = [], array $stepTwo = []): StudentRegistrationDraft
    {
        $this->saveBasic($college, $user, array_merge([
            'gender' => 'female',
        ], $stepOne))->assertRedirect(route('students.registration.step', ['step' => 2]));

        $this->saveStep($college, $user, 2, array_merge([
            'academic_year_id' => $stepTwo['academic_year_id'] ?? null,
            'program_id' => $stepTwo['program_id'] ?? null,
            'semester' => '1',
            'admission_date' => '2026-06-01',
            'enrollment_date' => '2026-06-02',
        ], $stepTwo))->assertRedirect(route('students.registration.step', ['step' => 3]));

        return $this->draftFor($college, $user);
    }

    // -----------------------------------------------------------------
    // Navigation + step validation
    // -----------------------------------------------------------------

    public function test_registration_entry_point_starts_the_wizard_and_shows_the_four_steps(): void
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
            ->assertSee('Step 1 of 4')
            ->assertSee('Basic Information')
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

        // Exactly one working copy — a save never accumulates empty drafts.
        $this->assertSame(1, StudentRegistrationDraft::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->where('user_id', $user->id)
            ->count());
    }

    public function test_draft_survives_leaving_the_wizard_and_resumes_on_the_current_step(): void
    {
        $college = $this->makeCollege('WIZ3');
        $user = $this->staff($college);

        // "Save draft" on step 1 keeps the draft ON step 1.
        $this->saveStep($college, $user, 1, ['first_name' => 'Aarav', 'last_name' => 'Shah'], draft: true)
            ->assertRedirect(route('students.registration.step', ['step' => 1]));

        $this->assertSame(1, $this->draftFor($college, $user)->current_step);

        // A new request (a later session) resumes the same draft on the same step.
        $this->asCollege($college, $user)->get(route('students.registration.step', ['step' => 1]))
            ->assertOk()
            ->assertSee('Aarav')
            ->assertSee('Shah');

        $this->asCollege($college, $user)->get(route('students.registration.create'))
            ->assertRedirect(route('students.registration.step', ['step' => 1]));
    }

    /**
     * Regression: the resume point must follow the draft's stored step, not a
     * hardcoded step 1. A "Save draft" leaves the marker where the applicant is;
     * "Save & continue" advances it.
     */
    public function test_resume_opens_the_step_the_draft_is_actually_on(): void
    {
        $college = $this->makeCollege('WIZ3B');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        // Continue from step 1 → the draft is on step 2.
        $this->saveStep($college, $user, 1, ['first_name' => 'Aarav'])
            ->assertRedirect(route('students.registration.step', ['step' => 2]));

        $this->assertSame(2, $this->draftFor($college, $user)->current_step);

        $this->asCollege($college, $user)->get(route('students.registration.create'))
            ->assertRedirect(route('students.registration.step', ['step' => 2]));

        // Saving a draft ON step 2 must not move the marker back to step 1.
        $this->saveStep($college, $user, 2, ['semester' => '3'], draft: true)
            ->assertRedirect(route('students.registration.step', ['step' => 2]));

        $this->assertSame(2, $this->draftFor($college, $user)->current_step);

        $this->asCollege($college, $user)->get(route('students.registration.create'))
            ->assertRedirect(route('students.registration.step', ['step' => 2]));

        // Going on to step 3 moves the resume point with it.
        $this->saveStep($college, $user, 2, [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'semester' => '3',
        ])->assertRedirect(route('students.registration.step', ['step' => 3]));

        $this->asCollege($college, $user)->get(route('students.registration.create'))
            ->assertRedirect(route('students.registration.step', ['step' => 3]));
    }

    public function test_a_failed_step_returns_to_that_step_with_field_errors(): void
    {
        $college = $this->makeCollege('WIZ4');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $this->saveStep($college, $user, 1, ['first_name' => 'Riya'])->assertRedirect(route('students.registration.step', ['step' => 2]));

        // Academic Record without the required context — back to STEP 2 (never
        // to "/" or to whatever page the browser came from), with the errors.
        $this->saveStep($college, $user, 2, ['semester' => '1'])
            ->assertRedirect(route('students.registration.step', ['step' => 2]))
            ->assertSessionHasErrors(['academic_year_id', 'program_id']);

        // The failed attempt wrote nothing.
        $this->assertSame([], $this->draftFor($college, $user)->stepData(2));

        // A failing DRAFT save on the same step also returns to step 2.
        $this->saveStep($college, $user, 2, ['academic_year_id' => 'not-an-id'], draft: true)
            ->assertRedirect(route('students.registration.step', ['step' => 2]))
            ->assertSessionHasErrors(['academic_year_id']);

        // The wizard is still where it was: the applicant can fix and continue.
        $this->saveStep($college, $user, 2, [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
        ])->assertRedirect(route('students.registration.step', ['step' => 3]));
    }

    public function test_academic_record_rejects_a_foreign_college_programme_and_section(): void
    {
        $college = $this->makeCollege('WIZ5');
        $other = $this->makeCollege('WIZ5B');
        $user = $this->staff($college);
        $otherYear = $this->makeYear($other);
        $otherProgram = $this->makeProgram($other);

        $this->saveStep($college, $user, 2, [
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

        // Sections B, C and D are one page: the personal details and the
        // parent/guardian block arrive in the SAME save.
        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya', 'middle_name' => 'R', 'last_name' => 'Verma', 'phone' => '9111111111',
            'father_name' => 'Suresh Verma',
            'father_phone' => '9222222222',
            'father_email' => 'suresh@example.test',
            'father_occupation' => 'Farmer',
            'guardian_relation' => 'father',
            // A tampered value must not survive: the server rebuilds it.
            'guardian_name' => 'Someone Else',
            'guardian_phone' => '9000000000',
        ])->assertRedirect(route('students.registration.step', ['step' => 2]));

        $step = $this->draftFor($college, $user)->stepData(1);
        $this->assertSame('Riya', $step['first_name'], 'Personal and guardian data share one step.');
        $this->assertSame('Suresh Verma', $step['guardian_name']);
        $this->assertSame('9222222222', $step['guardian_phone']);
        $this->assertSame('suresh@example.test', $step['guardian_email']);
        $this->assertSame('Farmer', $step['guardian_occupation']);

        // Switching to the mother re-derives from the mother's record.
        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'father_name' => 'Suresh Verma',
            'mother_name' => 'Kavita Verma',
            'mother_phone' => '9333333333',
            'guardian_relation' => 'mother',
        ])->assertRedirect();

        $step = $this->draftFor($college, $user)->stepData(1);
        $this->assertSame('Kavita Verma', $step['guardian_name']);
        $this->assertSame('9333333333', $step['guardian_phone']);
    }

    public function test_switching_to_other_preserves_the_details_that_were_on_screen(): void
    {
        $college = $this->makeCollege('WIZ9');
        $user = $this->staff($college);

        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'father_name' => 'Suresh Verma',
            'father_phone' => '9222222222',
            'guardian_relation' => 'father',
        ])->assertRedirect();

        // The browser submits the previously auto-filled values when the user
        // switches to "Other"; the server keeps them as independent data.
        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'father_name' => 'Suresh Verma',
            'father_phone' => '9222222222',
            'guardian_relation' => 'other',
            'guardian_name' => 'Suresh Verma',
            'guardian_phone' => '9222222222',
            'guardian_occupation' => 'Guardian',
        ])->assertRedirect();

        $step = $this->draftFor($college, $user)->stepData(1);
        $this->assertSame('other', $step['guardian_relation']);
        $this->assertSame('Suresh Verma', $step['guardian_name']);
        $this->assertSame('Guardian', $step['guardian_occupation']);
    }

    public function test_self_guardian_uses_the_students_own_details(): void
    {
        $college = $this->makeCollege('WIZ10');
        $user = $this->staff($college);

        // "Self" is chosen on the page that also holds the personal fields, and
        // the derivation must read the values typed in the same request.
        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya', 'last_name' => 'Verma',
            'phone' => '9111111111', 'email' => 'riya@example.test',
            'guardian_relation' => 'self',
        ])->assertRedirect();

        $step = $this->draftFor($college, $user)->stepData(1);
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

        $this->saveStep($college, $user, 2, [
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

        $rows = $this->draftFor($college, $user)->stepData(2)['qualifications'];
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

        $this->saveStep($college, $user, 2, [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'qualifications' => [
                ['level' => 'class_10', 'examination_year' => 2022],
                ['level' => 'class_12', 'examination_year' => 2024],
            ],
        ])->assertRedirect();

        $this->saveStep($college, $user, 2, [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'qualifications' => [
                ['level' => 'class_10', 'examination_year' => 2022],
                ['level' => 'class_12', 'examination_year' => 2024, 'remove' => '1'],
            ],
        ])->assertRedirect();

        $rows = $this->draftFor($college, $user)->stepData(2)['qualifications'];
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
        ])->assertRedirect(route('students.registration.step', ['step' => 3]))
            ->assertSessionHasErrors(['documents']);

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());

        // Attach the required document.
        $this->asCollege($college, $user)->post(route('students.registration.documents.upload'), [
            'draft_id' => $draft->id,
            'document_type_id' => $required->id,
            'file' => UploadedFile::fake()->create('transfer.pdf', 20, 'application/pdf'),
        ])->assertRedirect(route('students.registration.step', ['step' => 3]));

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
    // Unified academic workflow: order, dropdowns, dependence
    // -----------------------------------------------------------------

    public function test_the_current_course_fields_follow_the_canonical_academic_order(): void
    {
        $college = $this->makeCollege('WIZA1');
        $user = $this->staff($college);

        $this->asCollege($college, $user)->get(route('students.registration.create'))->assertRedirect();
        $html = $this->asCollege($college, $user)
            ->get(route('students.registration.step', ['step' => 2]))
            ->assertOk()
            ->getContent();

        // Academic Year → Programme → Semester/Year of study → Section/Batch →
        // Admission date → Enrollment date → Admission category. Asserting the
        // POSITIONS (not just presence) is the point: the same four levels must
        // appear in the same order here, on the enrollment form and on the profile.
        $order = [
            'id="academic_year_id"',
            'id="program_id"',
            'data-erp-academic-term-slot',
            'id="section_id"',
            'id="admission_date"',
            'id="enrollment_date"',
            'Admission category',
        ];

        $previous = -1;

        foreach ($order as $needle) {
            $position = strpos($html, $needle);
            $this->assertNotFalse($position, "\"{$needle}\" must render in the Current Course block.");
            $this->assertGreaterThan($previous, $position, "\"{$needle}\" is out of the canonical academic order.");
            $previous = $position;
        }

        // Section always comes AFTER the semester/year of study field.
        $this->assertGreaterThan(
            strpos($html, 'Semester / Year of study'),
            strpos($html, 'Section / Batch'),
            'Section / Batch must be offered after Semester / Year of study.',
        );
    }

    public function test_the_semester_field_is_a_configured_dropdown_for_the_chosen_academic_year(): void
    {
        $college = $this->makeCollege('WIZA2');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $term = $this->makeAcademicTerm($college, $year, 'SEM3', 'Semester 3', 3);

        // Save the academic context as a draft, then reopen the step: the field
        // must now be a list of the CONFIGURED levels of that year.
        $this->saveStep($college, $user, 2, [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'semester' => 'Semester 3',
        ], draft: true)->assertRedirect();

        $html = $this->asCollege($college, $user)
            ->get(route('students.registration.step', ['step' => 2]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<select[^>]*id="academic_term_id"/', $html, 'Semester / year of study must be a configured dropdown.');
        $this->assertStringNotContainsString('id="semester"', $html, 'The free-text semester input must be replaced once levels are configured.');
        $this->assertStringContainsString('Semester 3', $html);

        // The dropdown is scoped to that year: another year's term is not offered.
        $otherYear = $this->makeYear($college, '2027', '2027-28', '2027-06-01', '2028-05-31');
        $otherTerm = $this->makeAcademicTerm($college, $otherYear, 'SEMX', 'Semester 9', 9);

        $this->assertSame($term->id, (int) $this->draftFor($college, $user)->stepData(2)['academic_term_id']);
        $this->assertStringNotContainsString('Semester 9', $html);
        $this->assertNotSame($term->id, $otherTerm->id);
    }

    public function test_the_academic_options_endpoint_returns_only_the_selected_context(): void
    {
        $college = $this->makeCollege('WIZA3');
        $otherCollege = $this->makeCollege('WIZA3B');
        $user = $this->staff($college);

        $year = $this->makeYear($college);
        $nextYear = $this->makeYear($college, '2027', '2027-28', '2027-06-01', '2028-05-31');
        $program = $this->makeProgram($college);
        $otherProgram = $this->makeProgram($college, 'BCOM');

        $term = $this->makeAcademicTerm($college, $year, 'SEM1', 'Semester 1', 1);
        $nextTerm = $this->makeAcademicTerm($college, $nextYear, 'SEM1', 'Semester 1', 1);
        $section = $this->makeSection($college, $year, $program, 'A');
        $otherSection = $this->makeSection($college, $year, $otherProgram, 'B');

        $foreignYear = $this->makeYear($otherCollege, 'FOREIGN', 'Foreign year');
        $this->makeAcademicTerm($otherCollege, $foreignYear, 'FSEM', 'Foreign semester', 1);

        $response = $this->asCollege($college, $user)->getJson(route('students.registration.academic-options', [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
        ]))->assertOk();

        $this->assertTrue($response->json('terms_configured'));

        $termIds = array_column($response->json('data.terms'), 'id');
        $this->assertContains($term->id, $termIds);
        $this->assertNotContains($nextTerm->id, $termIds, 'Terms of another academic year must not be offered.');
        $this->assertStringContainsString('Semester 1', $response->json('data.terms.0.label'));

        $sectionIds = array_column($response->json('data.sections'), 'id');
        $this->assertContains($section->id, $sectionIds);
        $this->assertNotContains($otherSection->id, $sectionIds, 'Sections of another programme must not be offered.');

        // Another tenant's structure is never readable through the endpoint.
        $foreign = $this->asCollege($otherCollege, $this->staff($otherCollege))
            ->getJson(route('students.registration.academic-options', ['academic_year_id' => $year->id]))
            ->assertOk();

        $this->assertSame([], $foreign->json('data.terms'));
        $this->assertSame([], $foreign->json('data.sections'));
    }

    public function test_a_term_from_another_academic_year_is_rejected_on_save(): void
    {
        $college = $this->makeCollege('WIZA4');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $nextYear = $this->makeYear($college, '2027', '2027-28', '2027-06-01', '2028-05-31');
        $program = $this->makeProgram($college);
        $nextTerm = $this->makeAcademicTerm($college, $nextYear, 'SEM1', 'Semester 1', 1);

        $this->saveStep($college, $user, 2, [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'academic_term_id' => $nextTerm->id,
        ])->assertSessionHasErrors(['academic_term_id']);
    }

    public function test_a_term_from_another_college_is_rejected_on_save(): void
    {
        $college = $this->makeCollege('WIZA5');
        $other = $this->makeCollege('WIZA5B');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $otherYear = $this->makeYear($other, '2026', '2026-27');
        $foreignTerm = $this->makeAcademicTerm($other, $otherYear, 'SEM1', 'Semester 1', 1);

        $this->saveStep($college, $user, 2, [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'academic_term_id' => $foreignTerm->id,
        ])->assertSessionHasErrors(['academic_term_id']);
    }

    public function test_the_approved_registration_creates_the_enrollment_with_the_configured_term(): void
    {
        $college = $this->makeCollege('WIZA6');
        $submitter = $this->staff($college);
        $approver = $this->makeUserWithPermissions($college, ['students.view', 'students.create', 'approvals.view', 'approvals.approve']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $section = $this->makeSection($college, $year, $program, 'A');
        $term = $this->makeAcademicTerm($college, $year, 'SEM3', 'Semester 3', 3);

        $draft = $this->completeDraft($college, $submitter, [], [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'academic_term_id' => $term->id,
            'section_id' => $section->id,
        ]);

        // The term reference and the human label are agreed at save time.
        $stored = $this->draftFor($college, $submitter)->stepData(2);
        $this->assertSame($term->id, (int) $stored['academic_term_id']);
        $this->assertSame('Semester 3', $stored['semester']);

        $this->asCollege($college, $submitter)->post(route('students.registration.submit'), [
            'draft_id' => $draft->id,
            'confirm_declaration' => '1',
        ])->assertRedirect(route('students.index'));

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->sole();
        $request = ApprovalRequest::withoutGlobalScopes()->where('target_id', $student->id)->sole();

        $this->asCollege($college, $approver)->post(route('approvals.approve', $request), [])->assertRedirect();

        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->sole();

        $this->assertSame($term->id, (int) $enrollment->academic_term_id, 'Approval must carry the configured semester into the enrollment.');
        $this->assertSame($section->id, (int) $enrollment->section_id);
        $this->assertSame('Semester 3', $enrollment->semester);
        $this->assertSame('Semester 3', $enrollment->semesterLabel());
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
            ->post(route('students.registration.step.save', ['step' => 1]), ['draft_id' => $draft->id, 'first_name' => 'Intruder'])
            ->assertNotFound();

        // The owner's data is untouched.
        $this->assertSame('Riya', $draft->fresh()->stepData(1)['first_name']);

        // Refusing the intruder must not start a draft of their own either.
        $this->assertSame(0, StudentRegistrationDraft::withoutGlobalScopes()
            ->where('college_id', $other->id)
            ->where('user_id', $intruder->id)
            ->count());
    }

    /**
     * Naming somebody else's draft id is a 404 on every registration route —
     * across colleges AND across two users of the same college — and the attempt
     * never leaves a replacement draft behind.
     */
    public function test_a_foreign_draft_id_is_refused_on_every_registration_route(): void
    {
        Storage::fake('private');

        $college = $this->makeCollege('WIZ19C');
        $other = $this->makeCollege('WIZ19D');

        $owner = $this->staff($college);
        $peer = $this->staff($college);
        $outsider = $this->staff($other);

        $this->saveStep($college, $owner, 1, ['first_name' => 'Riya'])->assertRedirect();
        $draft = $this->draftFor($college, $owner);

        $attempts = [
            'another user in the same college' => [$college, $peer],
            'a user in another college' => [$other, $outsider],
        ];

        foreach ($attempts as $label => [$tenant, $intruder]) {
            // GET a step while naming the draft in the query string.
            $this->asCollege($tenant, $intruder)
                ->get(route('students.registration.step', ['step' => 1]).'?draft_id='.$draft->id)
                ->assertNotFound();

            // POST a step naming it.
            $this->asCollege($tenant, $intruder)
                ->post(route('students.registration.step.save', ['step' => 1]), [
                    'draft_id' => $draft->id,
                    'first_name' => 'Intruder',
                ])
                ->assertNotFound();

            // The private-image stream and the discard endpoint are scoped the
            // same way, so neither can be used to read or destroy the draft.
            $this->asCollege($tenant, $intruder)
                ->get(route('students.registration.image', ['field' => 'photo']).'?draft_id='.$draft->id)
                ->assertNotFound();

            $this->asCollege($tenant, $intruder)
                ->post(route('students.registration.cancel'), ['draft_id' => $draft->id])
                ->assertNotFound();

            // Nothing was started for the intruder by any of the attempts.
            $this->assertSame(0, StudentRegistrationDraft::withoutGlobalScopes()
                ->where('college_id', $tenant->id)
                ->where('user_id', $intruder->id)
                ->count(), "A refused attempt ({$label}) must not start a draft.");
        }

        // The owner's draft is untouched and is still the only one in the college.
        $fresh = $draft->fresh();
        $this->assertSame('Riya', $fresh->stepData(1)['first_name']);
        $this->assertNull($fresh->stepData(1)['father_name'] ?? null);
        $this->assertSame(StudentRegistrationDraft::STATUS_DRAFT, $fresh->status);
        $this->assertSame(1, StudentRegistrationDraft::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->count());
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
        Storage::fake('private');
        $college = $this->makeCollege('WIZ22');
        $user = $this->staff($college);

        // An approved (legacy) student already owns this phone number.
        $blocker = $this->makeStudent($college, ['student_number' => 'STU-BLOCKER', 'phone' => '9555555555']);

        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'phone' => '9555555555',
            // A real (1×1) PNG so the `image` rule is genuinely exercised; the
            // point is that it must never reach the private disk.
            'photo' => UploadedFile::fake()->createWithContent('portrait.png', $this->onePixelPng()),
        ])->assertRedirect(route('students.registration.step', ['step' => 1]))
            ->assertSessionHasErrors(['phone']);

        // Validation failed BEFORE any write: only the pre-existing student
        // exists, nothing else was created, no file was stored and the draft
        // holds no step-1 payload.
        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame($blocker->id, (int) Student::withoutGlobalScopes()->where('college_id', $college->id)->sole()->id);
        $this->assertSame(0, StudentQualification::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(0, ApprovalRequest::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame([], Storage::disk('private')->allFiles());

        // Not even a draft row: the draft is only created once a save is
        // certain, so a rejected registration leaves no working copy behind.
        $this->assertSame(0, StudentRegistrationDraft::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->where('user_id', $user->id)
            ->count());

        // A free number registers normally afterwards — one draft, one row.
        $this->saveStep($college, $user, 1, ['first_name' => 'Riya', 'phone' => '9555555556'])
            ->assertRedirect(route('students.registration.step', ['step' => 2]));
        $this->assertSame('Riya', $this->draftFor($college, $user)->stepData(1)['first_name']);
        $this->assertSame(1, StudentRegistrationDraft::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->where('user_id', $user->id)
            ->count());

        // Repeating the duplicate from an EXISTING draft neither duplicates the
        // row nor overwrites what the applicant already saved.
        $this->saveStep($college, $user, 1, ['first_name' => 'Changed', 'phone' => '9555555555'])
            ->assertRedirect(route('students.registration.step', ['step' => 1]))
            ->assertSessionHasErrors(['phone']);

        $this->assertSame(1, StudentRegistrationDraft::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->where('user_id', $user->id)
            ->count());
        $this->assertSame('Riya', $this->draftFor($college, $user)->stepData(1)['first_name']);
    }

    /**
     * Regression: a duplicate discovered at SUBMIT time (the number was taken
     * while the draft was open) must fail the submission atomically — no student,
     * no qualification, no approval request — and leave the draft intact so the
     * applicant can correct it.
     */
    public function test_a_phone_taken_after_the_draft_started_blocks_submission_without_partial_writes(): void
    {
        $college = $this->makeCollege('WIZ22B');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        // A complete draft, including a previous qualification.
        $draft = $this->completeDraft($college, $user, [], [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
        ]);

        $this->saveStep($college, $user, 2, [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'qualifications' => [[
                'level' => 'class_10',
                'examination_year' => 2022,
                'marks_obtained' => 430,
                'max_marks' => 500,
            ]],
        ])->assertRedirect(route('students.registration.step', ['step' => 3]));

        // Meanwhile the same mobile number is registered for an approved student.
        $blocker = $this->makeStudent($college, ['student_number' => 'STU-TAKEN', 'phone' => '9111111111']);

        $this->asCollege($college, $user)->post(route('students.registration.submit'), [
            'draft_id' => $draft->id,
            'confirm_declaration' => '1',
        ])->assertRedirect(route('students.registration.step', ['step' => 1]))
            ->assertSessionHasErrors(['phone']);

        // Nothing partial was written, and the draft was not consumed.
        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(0, StudentQualification::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(0, ApprovalRequest::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertFalse($draft->refresh()->isSubmitted());
        $this->assertNull($draft->submitted_at);
        $this->assertNull($draft->approval_request_id);
        $this->assertNull($draft->student_id);

        // Once the number is free the same draft submits normally.
        $blocker->update(['phone' => '9000000009']);

        $this->asCollege($college, $user)->post(route('students.registration.submit'), [
            'draft_id' => $draft->id,
            'confirm_declaration' => '1',
        ])->assertRedirect(route('students.index'));

        $student = Student::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->whereKeyNot($blocker->id)
            ->sole();

        $this->assertSame(Student::REGISTRATION_PENDING, $student->registration_status);
        $this->assertSame(1, StudentQualification::withoutGlobalScopes()->where('student_id', $student->id)->count());
        $this->assertSame(1, ApprovalRequest::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->where('action', 'registration_approval')
            ->count());
    }

    // -----------------------------------------------------------------
    // Step 1 — layout, section order, Save draft
    // -----------------------------------------------------------------

    /** Open the wizard and return the rendered step-1 HTML. */
    private function stepOneHtml(College $college, User $user): string
    {
        $this->asCollege($college, $user)->get(route('students.registration.create'))->assertRedirect();

        return $this->asCollege($college, $user)
            ->get(route('students.registration.step', ['step' => 1]))
            ->assertOk()
            ->getContent();
    }

    /** Assert a rendered <select> is NOT disabled. */
    private function assertSelectEnabled(string $html, string $id): void
    {
        preg_match('/<select[^>]*id="'.preg_quote($id, '/').'"[^>]*>/', $html, $match);

        $this->assertNotEmpty($match, "The {$id} select must render.");
        $this->assertStringNotContainsString('disabled', $match[0], "The {$id} select must be usable.");
    }

    public function test_step_one_sections_are_unnumbered_and_run_from_identification_to_signatures(): void
    {
        $college = $this->makeCollege('WIZ30');
        $user = $this->staff($college);

        $html = $this->stepOneHtml($college, $user);

        // No internal section numbering: the four-step bar is the only numbering.
        $this->assertStringNotContainsString('erp-card__num', $html, 'Internal section headings carry no numbers.');
        $this->assertStringNotContainsString('>01<', $html);
        $this->assertStringNotContainsString('>02<', $html);

        // A Student Identification → B Personal → C Parent → D Guardian →
        // E Permanent → F Temporary → G Photograph → H Signatures.
        $order = [
            'id="wz-basic-identifiers"',   // A
            'id="wz-basic-personal"',      // B
            'id="wz-basic-parents"',       // C
            'id="wz-basic-guardian"',      // D
            'id="wz-basic-permanent"',     // E
            'id="wz-basic-temporary"',     // F
            'id="wz-basic-photograph"',    // G
            'id="wz-basic-signature"',     // H
        ];
        $positions = array_map(fn (string $needle) => strpos($html, $needle), $order);

        foreach ($positions as $index => $position) {
            $this->assertNotFalse($position, "The \"{$order[$index]}\" section must render.");
        }

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'The sections must render in the required order.');

        // Section A is Student Identification and it comes first; B is Personal
        // Details — the two headings the brief names explicitly.
        $this->assertLessThan(
            strpos($html, 'Personal Details'),
            strpos($html, 'Student Identification'),
            'Student Identification is section A, before Personal Details.',
        );
        $this->assertLessThan(strpos($html, 'Parent Details'), strpos($html, 'Personal Details'));

        // A read-only identifier always has a label and an honest value, never a
        // blank box: before submission all three are reported as pending.
        foreach (['student_id', 'admission_number', 'roll_number'] as $key) {
            $this->assertStringContainsString('data-erp-identifier="'.$key.'"', $html);
        }
        $this->assertStringContainsString('data-erp-identifier-value="student_id"', $html);
        $this->assertStringContainsString('Pending', $html);

        // There is no input for any identifier: the wizard cannot set one.
        foreach (['student_number', 'admission_number', 'roll_number'] as $field) {
            $this->assertStringNotContainsString('name="'.$field.'"', $html, "The {$field} is never user-editable.");
        }

        // The media controls all survive the re-ordering.
        foreach (['photo', 'signature', 'father_photo', 'mother_photo', 'guardian_photo'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html, "The {$field} upload must still exist.");
        }

        // Both addresses are present, and the temporary one carries the toggle.
        $this->assertStringContainsString('name="address_line_1"', $html);
        $this->assertStringContainsString('data-erp-same-address', $html);
        $this->assertStringContainsString('name="correspondence_address_line_1"', $html);
    }

    public function test_step_one_offers_a_visible_save_draft_action(): void
    {
        $college = $this->makeCollege('WIZ31');
        $user = $this->staff($college);

        $html = $this->stepOneHtml($college, $user);

        $this->assertStringContainsString('data-erp-save-draft', $html, 'Step 1 needs an explicit Save draft control.');
        $this->assertMatchesRegularExpression('/>\s*Save draft\s*</', $html, 'The control is labelled "Save draft".');
        $this->assertStringContainsString(
            route('students.registration.step.draft', ['step' => 1]),
            $html,
            'Save draft posts to the draft endpoint of the current step.',
        );
    }

    public function test_save_draft_on_step_one_saves_without_advancing_or_submitting(): void
    {
        $college = $this->makeCollege('WIZ32');
        $user = $this->staff($college);

        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'phone' => '9333333333',
        ], draft: true)
            ->assertRedirect(route('students.registration.step', ['step' => 1]))
            ->assertSessionHas('success');

        $draft = $this->draftFor($college, $user);

        $this->assertSame(1, (int) $draft->current_step, 'Save draft keeps the applicant on the step they are editing.');
        $this->assertSame('Riya', $draft->stepData(1)['first_name'], 'The typed data is saved.');
        $this->assertFalse($draft->isSubmitted());
        $this->assertSame(1, StudentRegistrationDraft::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->where('user_id', $user->id)
            ->count(), 'Save draft never creates a second draft.');
        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(0, ApprovalRequest::withoutGlobalScopes()->where('college_id', $college->id)->count());

        // A second Save draft edits the same row instead of adding another.
        $this->saveStep($college, $user, 1, ['first_name' => 'Riya', 'last_name' => 'Verma'], draft: true)
            ->assertRedirect(route('students.registration.step', ['step' => 1]));

        $this->assertSame(1, StudentRegistrationDraft::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->where('user_id', $user->id)
            ->count());
        $this->assertSame('Verma', $this->draftFor($college, $user)->stepData(1)['last_name']);
    }

    /**
     * A rejected save with no draft behind it writes nothing, and a GET of a step
     * never starts a draft as a side effect. The entry point is the only place
     * that begins one, and it reopens the step that failed with the error intact.
     */
    public function test_a_rejected_first_save_leaves_no_draft_and_the_entry_point_reopens_the_failed_step(): void
    {
        $college = $this->makeCollege('WIZ32B');
        $user = $this->staff($college);

        $drafts = fn (): int => StudentRegistrationDraft::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->where('user_id', $user->id)
            ->count();

        // Step 2 (academic year + program are required) with no draft at all.
        $this->saveStep($college, $user, 2, [])
            ->assertRedirect(route('students.registration.step', ['step' => 2]))
            ->assertSessionHasErrors(['academic_year_id']);

        $this->assertSame(0, $drafts(), 'A rejected first save leaves no working copy.');

        // Reading a step is not a way to create one either.
        $this->asCollege($college, $user)
            ->get(route('students.registration.step', ['step' => 2]))
            ->assertRedirect(route('students.registration.create'));

        $this->assertSame(0, $drafts(), 'A GET must never start a draft.');

        // The entry point starts it — for this user only — and reopens step 2,
        // which is where the rejected save was, so the message is still shown.
        $this->asCollege($college, $user)
            ->get(route('students.registration.create'))
            ->assertRedirect(route('students.registration.step', ['step' => 2]))
            ->assertSessionHasErrors(['academic_year_id']);

        $this->assertSame(1, $drafts(), 'Exactly one working copy is created.');

        // The draft is genuinely empty: the failed attempt stored nothing.
        $draft = $this->draftFor($college, $user);
        $this->assertSame([], $draft->stepData(2));
        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(0, ApprovalRequest::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_save_draft_keeps_a_previously_uploaded_photograph(): void
    {
        Storage::fake('private');
        $college = $this->makeCollege('WIZ32C');
        $user = $this->staff($college);

        // A strict save with the photograph attached.
        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'photo' => UploadedFile::fake()->createWithContent('portrait.png', $this->onePixelPng()),
        ])->assertRedirect(route('students.registration.step', ['step' => 2]));

        $stored = $this->draftFor($college, $user)->stepData(1)['photo_path'];

        $this->assertNotEmpty($stored);
        $this->assertCount(1, Storage::disk('private')->allFiles());

        // "Save draft" later, without re-selecting the file: it must be retained.
        $this->saveStep($college, $user, 1, ['first_name' => 'Riya'], draft: true)
            ->assertRedirect(route('students.registration.step', ['step' => 1]));

        $this->assertSame($stored, $this->draftFor($college, $user)->stepData(1)['photo_path']);
        $this->assertCount(1, Storage::disk('private')->allFiles(), 'No duplicate blob is written for an unchanged file.');
    }

    // -----------------------------------------------------------------
    // Step 1 — geography hierarchy
    // -----------------------------------------------------------------

    /**
     * A five-level fixture directory. India is the default country and the
     * deeper levels are the ones a college would import.
     *
     * @return array<string, GeoLocation>
     */
    private function geoDirectory(): array
    {
        $country = GeoLocation::create(['level' => 'country', 'name' => 'India', 'code' => 'IN', 'is_active' => true]);
        $state = GeoLocation::create(['level' => 'state', 'name' => 'Madhya Pradesh', 'code' => 'MP', 'parent_id' => $country->id, 'is_active' => true]);
        $district = GeoLocation::create(['level' => 'district', 'name' => 'Bhopal', 'parent_id' => $state->id, 'is_active' => true]);
        $block = GeoLocation::create(['level' => 'block', 'name' => 'Berasia', 'parent_id' => $district->id, 'is_active' => true]);
        $village = GeoLocation::create(['level' => 'village', 'name' => 'Barkheda', 'parent_id' => $block->id, 'is_active' => true]);

        return compact('country', 'state', 'district', 'block', 'village');
    }

    public function test_the_country_defaults_to_india_and_its_states_are_ready_to_choose(): void
    {
        $college = $this->makeCollege('WIZ33');
        $user = $this->staff($college);
        $geo = $this->geoDirectory();

        $html = $this->stepOneHtml($college, $user);

        // Country pre-selected (India), so no empty "— Select —" box.
        // @selected renders a bare `selected` attribute (Blade compiler).
        $this->assertStringContainsString(
            'value="'.$geo['country']->id.'" selected>',
            $html,
            'India is the default country when the directory holds it.',
        );
        $this->assertStringContainsString('Madhya Pradesh', $html, 'The state list is populated for the default country.');

        // And the state select is usable straight away, because its parent is set.
        $this->assertSelectEnabled($html, 'state_id');

        // The district select only becomes usable once a state is chosen.
        preg_match('/<select[^>]*id="district_id"[^>]*>/', $html, $district);
        $this->assertNotEmpty($district);
        $this->assertStringContainsString('disabled', $district[0], 'A level stays disabled until its parent has a value.');
    }

    public function test_the_address_selection_is_preselected_when_resuming_a_draft(): void
    {
        $college = $this->makeCollege('WIZ34');
        $user = $this->staff($college);
        $geo = $this->geoDirectory();

        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'country_id' => $geo['country']->id,
            'state_id' => $geo['state']->id,
            'district_id' => $geo['district']->id,
            'block_id' => $geo['block']->id,
            'village_id' => $geo['village']->id,
            'correspondence_same_as_permanent' => '1',
        ])->assertRedirect(route('students.registration.step', ['step' => 2]));

        $html = $this->asCollege($college, $user)
            ->get(route('students.registration.step', ['step' => 1]))
            ->assertOk()
            ->getContent();

        foreach (['country', 'state', 'district', 'block', 'village'] as $level) {
            $this->assertStringContainsString(
                'value="'.$geo[$level]->id.'" selected>',
                $html,
                "The saved {$level} must still be selected when the draft is resumed.",
            );
        }

        // "Same as permanent" is remembered, and its block stays collapsed.
        $this->assertMatchesRegularExpression('/data-erp-same-address[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/data-erp-correspondence[^>]*hidden/', $html);
    }

    public function test_the_address_endpoint_serves_each_level_of_the_chain(): void
    {
        $college = $this->makeCollege('WIZ35');
        $user = $this->staff($college);
        $geo = $this->geoDirectory();

        $this->asCollege($college, $user)
            ->getJson(route('students.registration.geo.children', ['level' => 'state', 'parent_id' => $geo['country']->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Madhya Pradesh')
            ->assertJsonPath('data.0.id', (int) $geo['state']->id);

        $this->asCollege($college, $user)
            ->getJson(route('students.registration.geo.children', ['level' => 'district', 'parent_id' => $geo['state']->id]))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Bhopal');

        $this->asCollege($college, $user)
            ->getJson(route('students.registration.geo.children', ['level' => 'block', 'parent_id' => $geo['district']->id]))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Berasia');

        $this->asCollege($college, $user)
            ->getJson(route('students.registration.geo.children', ['level' => 'village', 'parent_id' => $geo['block']->id]))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Barkheda');

        // A level the directory does not hold answers with an empty list, never
        // with invented names.
        $this->asCollege($college, $user)
            ->getJson(route('students.registration.geo.children', ['level' => 'state', 'parent_id' => $geo['district']->id]))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Roots are served too (a country list needs no parent).
        $this->asCollege($college, $user)
            ->getJson(route('students.registration.geo.children', ['level' => 'country']))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'India');

        // An unknown level is an empty list, never an error page or invented data.
        $this->asCollege($college, $user)
            ->getJson(route('students.registration.geo.children', ['level' => 'planet']))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // A user without students.create cannot read the directory.

        $stranger = $this->makeUserWithPermissions($college, ['students.view']);
        $this->asCollege($college, $stranger)
            ->getJson(route('students.registration.geo.children', ['level' => 'state']))
            ->assertForbidden();
    }

    public function test_the_shipped_geography_seeder_loads_india_and_is_idempotent(): void
    {
        // First run: the country and its states come from database/data/geo_locations.json.
        $this->seed(GeoLocationSeeder::class);

        $india = GeoLocation::query()->where('level', 'country')->where('code', 'IN')->sole();
        $this->assertSame('India', $india->name);
        $this->assertNull($india->parent_id, 'A country is a root node.');

        $states = GeoLocation::query()->where('level', 'state')->where('parent_id', $india->id)->get();
        $this->assertGreaterThanOrEqual(28, $states->count(), 'Every state of India is loaded.');
        $this->assertTrue($states->contains(fn (GeoLocation $state) => $state->name === 'Madhya Pradesh'));
        $this->assertTrue(
            $states->every(fn (GeoLocation $state) => $state->parent_id === $india->id),
            'Every state belongs to the country, not to another state.',
        );

        // No deeper level is invented.
        $this->assertSame(0, GeoLocation::query()->where('level', 'district')->count());

        $before = GeoLocation::query()->count();

        // A second run must add nothing at all.
        $this->seed(GeoLocationSeeder::class);

        $this->assertSame($before, GeoLocation::query()->count(), 'Seeding twice must not duplicate a single row.');
    }

    public function test_the_wizard_uses_the_seeded_india_directory_end_to_end(): void
    {
        $this->seed(GeoLocationSeeder::class);

        $college = $this->makeCollege('WIZ36B');
        $user = $this->staff($college);

        $html = $this->stepOneHtml($college, $user);

        $india = GeoLocation::query()->where('level', 'country')->where('code', 'IN')->sole();

        $this->assertStringContainsString('value="'.$india->id.'" selected>', $html);
        $this->assertStringContainsString('Madhya Pradesh', $html);

        $madhyaPradesh = GeoLocation::query()
            ->where('level', 'state')
            ->where('name', 'Madhya Pradesh')
            ->sole();

        // Country + state is a valid chain even when the deeper levels are absent.
        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'country_id' => $india->id,
            'state_id' => $madhyaPradesh->id,
        ])->assertRedirect(route('students.registration.step', ['step' => 2]));

        $step = $this->draftFor($college, $user)->stepData(1);

        $this->assertSame('India', $step['country']);
        $this->assertSame('Madhya Pradesh', $step['state']);
    }

    public function test_an_empty_geography_directory_falls_back_to_free_text_without_breaking_the_step(): void
    {
        $college = $this->makeCollege('WIZ36');
        $user = $this->staff($college);

        $html = $this->stepOneHtml($college, $user);

        // No directory: text inputs, a clear note, and the step still saves.
        $this->assertStringContainsString('No geography directory is loaded', $html);
        $this->assertStringContainsString('name="state"', $html);
        $this->assertStringNotContainsString('data-geo-level="state"', $html);

        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'state' => 'Madhya Pradesh',
            'city' => 'Bhopal',
        ])->assertRedirect(route('students.registration.step', ['step' => 2]));
    }

    // -----------------------------------------------------------------
    // Step 2 — multiple qualifications
    // -----------------------------------------------------------------

    public function test_qualifications_can_be_added_resumed_and_edited_individually(): void
    {
        Storage::fake('private');
        $college = $this->makeCollege('WIZ37');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $this->completeDraft($college, $user, [], [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
        ]);

        // Three rows, the way the "+ Add Qualification" button produces them.
        $this->saveStep($college, $user, 2, [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'qualifications' => [
                ['level' => 'class_10', 'qualification' => 'High School', 'examination_year' => 2020, 'marks_obtained' => 400, 'max_marks' => 500],
                ['level' => 'class_12', 'qualification' => 'Higher Secondary', 'examination_year' => 2022, 'marks_obtained' => 450, 'max_marks' => 500],
                ['level' => 'graduation', 'qualification' => 'B.Sc', 'examination_year' => 2025, 'grade' => 'A'],
            ],
        ])->assertRedirect(route('students.registration.step', ['step' => 3]));

        $this->assertCount(3, $this->draftFor($college, $user)->stepData(2)['qualifications']);

        // The page is the repeatable form, with the visible add control.
        $html = $this->asCollege($college, $user)
            ->get(route('students.registration.step', ['step' => 2]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('+ Add Qualification', $html);
        // Three stored rows carry a numeric index; the fourth is the template
        // (its index is the __INDEX__ placeholder the script replaces).
        preg_match_all('/data-erp-qualification data-index="\d+"/', $html, $renderedRows);
        $this->assertCount(3, $renderedRows[0], 'Every stored row is re-rendered with a stable numeric index.');
        $this->assertSame(1, substr_count($html, 'data-index="__INDEX__"'), 'Exactly one row template is rendered.');
        $this->assertStringContainsString('Qualification 1', $html);
        $this->assertStringContainsString('Qualification 3', $html);
        $this->assertStringContainsString('B.Sc', $html, 'The saved values are shown when the draft is resumed.');
        $this->assertStringContainsString('data-erp-qualification-template', $html, 'The template for a new row is present.');

        // Edit the second row and remove the third: the first must be untouched.
        $this->saveStep($college, $user, 2, [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'qualifications' => [
                ['level' => 'class_10', 'qualification' => 'High School', 'examination_year' => 2020, 'marks_obtained' => 400, 'max_marks' => 500],
                ['level' => 'class_12', 'qualification' => 'Higher Secondary (edited)', 'examination_year' => 2023, 'marks_obtained' => 460, 'max_marks' => 500],
                ['level' => 'graduation', 'qualification' => 'B.Sc', 'examination_year' => 2025, 'remove' => '1'],
            ],
        ])->assertRedirect();

        $rows = $this->draftFor($college, $user)->stepData(2)['qualifications'];

        $this->assertCount(2, $rows, 'Removing one row leaves the others in place.');
        $this->assertSame('High School', $rows[0]['qualification']);
        $this->assertSame(2020, (int) $rows[0]['examination_year']);
        $this->assertSame('Higher Secondary (edited)', $rows[1]['qualification']);
        $this->assertSame(2023, (int) $rows[1]['examination_year']);
    }

    public function test_marks_greater_than_the_maximum_never_produce_a_percentage(): void
    {
        $college = $this->makeCollege('WIZ38');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $this->saveStep($college, $user, 2, [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'qualifications' => [
                ['level' => 'class_10', 'examination_year' => 2020, 'marks_obtained' => 600, 'max_marks' => 500],
                ['level' => 'class_12', 'examination_year' => 2022, 'marks_obtained' => 300, 'max_marks' => 0],
            ],
        ])->assertRedirect();

        $rows = $this->draftFor($college, $user)->stepData(2)['qualifications'];

        foreach ($rows as $row) {
            $this->assertTrue(
                blank($row['percentage'] ?? null),
                'An impossible marks pair must not be turned into a percentage.',
            );
        }
    }

    // -----------------------------------------------------------------
    // Step 3 — checkbox-based document selection
    // -----------------------------------------------------------------

    public function test_the_document_step_uses_a_compact_checkbox_picker(): void
    {
        Storage::fake('private');
        $college = $this->makeCollege('WIZ39');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $required = $this->makeDocumentType($college, 'TRANSFER');
        $required->programs()->attach($program->id, [
            'college_id' => $college->id, 'is_required' => true, 'can_submit_later' => false,
        ]);

        $optional = $this->makeDocumentType($college, 'SPORTS');

        $this->completeDraft($college, $user, [], [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
        ]);

        $html = $this->asCollege($college, $user)
            ->get(route('students.registration.step', ['step' => 3]))
            ->assertOk()
            ->getContent();

        // The old bulky heading is gone; the compact picker is in.
        $this->assertStringNotContainsString('Document Required For', $html);
        $this->assertStringContainsString('Select documents', $html);
        $this->assertStringContainsString('data-erp-doc-picker', $html);

        // Every configured type is one checkbox + one upload card.
        $this->assertStringContainsString('data-erp-doc-toggle="'.$required->id.'"', $html);
        $this->assertStringContainsString('data-erp-doc-toggle="'.$optional->id.'"', $html);
        $this->assertStringContainsString('data-erp-doc-card="'.$required->id.'"', $html);
        $this->assertStringContainsString('data-erp-doc-card="'.$optional->id.'"', $html);

        // The course-required type is locked; the optional one is not.
        preg_match('/<input[^>]*data-erp-doc-toggle="'.$required->id.'"[^>]*>/', $html, $requiredInput);
        $this->assertNotEmpty($requiredInput);
        $this->assertStringContainsString('disabled', $requiredInput[0], 'A required document cannot be un-ticked.');
        $this->assertStringContainsString('checked', $requiredInput[0], 'A required document starts selected.');

        preg_match('/<input[^>]*data-erp-doc-toggle="'.$optional->id.'"[^>]*>/', $html, $optionalInput);
        $this->assertNotEmpty($optionalInput);
        $this->assertStringNotContainsString('disabled', $optionalInput[0], 'An optional document is the applicant\'s choice.');

        // Required / optional are distinguishable, and the upload state is shown.
        $this->assertStringContainsString('Required', $html);
        $this->assertStringContainsString('Not uploaded', $html);
    }

    public function test_a_staged_document_stays_selected_and_visible_after_a_reload(): void
    {
        Storage::fake('private');
        $college = $this->makeCollege('WIZ40');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $type = $this->makeDocumentType($college, 'SPORTS');

        $draft = $this->completeDraft($college, $user, [], [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
        ]);

        $this->asCollege($college, $user)->post(route('students.registration.documents.upload'), [
            'draft_id' => $draft->id,
            'document_type_id' => $type->id,
            'file' => UploadedFile::fake()->create('sports.pdf', 20, 'application/pdf'),
        ])->assertRedirect(route('students.registration.step', ['step' => 3]));

        $html = $this->asCollege($college, $user)
            ->get(route('students.registration.step', ['step' => 3]))
            ->assertOk()
            ->getContent();

        // The card is marked as holding a file, so the client never hides it on
        // deselect, and the checkbox comes back ticked.
        $this->assertMatchesRegularExpression(
            '/data-erp-doc-card="'.$type->id.'"[^>]*data-erp-doc-attached="true"/',
            $html,
            'A card with a staged file must be identifiable so it is never hidden.',
        );
        preg_match('/<input[^>]*data-erp-doc-toggle="'.$type->id.'"[^>]*>/', $html, $input);
        $this->assertNotEmpty($input);
        $this->assertStringContainsString('checked', $input[0], 'A type with an attached file resumes selected.');
        $this->assertStringContainsString('Uploaded', $html);
        $this->assertStringContainsString(
            route('students.registration.documents.download', ['document' => $draft->documents()->first()->id]),
            $html,
            'The staged file is reached through the authorised download route.',
        );

        // And no stored path leaks into the page.
        $this->assertStringNotContainsString('registration-drafts/', $html);
    }

    public function test_un_ticking_a_required_document_cannot_bypass_the_submission_check(): void
    {
        Storage::fake('private');
        $college = $this->makeCollege('WIZ41');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $required = $this->makeDocumentType($college, 'TRANSFER');
        $required->programs()->attach($program->id, [
            'college_id' => $college->id, 'is_required' => true, 'can_submit_later' => false,
        ]);

        $draft = $this->completeDraft($college, $user, [], [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
        ]);

        // A hand-crafted POST that claims nothing is selected must still be
        // refused: the server reads the STORED files, not the checkbox state.
        $this->asCollege($college, $user)->post(route('students.registration.submit'), [
            'draft_id' => $draft->id,
            'confirm_declaration' => '1',
            'selected_documents' => [],
        ])->assertRedirect(route('students.registration.step', ['step' => 3]))
            ->assertSessionHasErrors(['documents']);

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());

        // The requirement is satisfied only by actually attaching the file.
        $this->asCollege($college, $user)->post(route('students.registration.documents.upload'), [
            'draft_id' => $draft->id,
            'document_type_id' => $required->id,
            'file' => UploadedFile::fake()->create('transfer.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $this->asCollege($college, $user)->post(route('students.registration.submit'), [
            'draft_id' => $draft->id,
            'confirm_declaration' => '1',
        ])->assertRedirect(route('students.index'));

        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    // -----------------------------------------------------------------
    // Part 2 — the three identifiers
    // -----------------------------------------------------------------

    public function test_the_wizard_never_allocates_an_identifier(): void
    {
        Storage::fake('private');
        $college = $this->makeCollege('WIZ50');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        // Opening the wizard starts the working copy — and nothing else.
        $this->asCollege($college, $user)->get(route('students.registration.create'))->assertRedirect();
        $draft = $this->draftFor($college, $user);

        // Saving steps, in draft mode and in strict mode, allocates nothing.
        $this->saveBasic($college, $user, ['aadhaar_number' => '9999 4105 7058'], draft: true)->assertRedirect();
        $this->saveStep($college, $user, 2, [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'semester' => '1',
        ], draft: true)->assertRedirect();

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(0, Admission::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());

        // No identifier is stored on the draft either.
        $stored = (string) json_encode($draft->fresh()->payload);
        foreach (['student_number', 'admission_number', 'roll_number'] as $key) {
            $this->assertStringNotContainsString($key, $stored, "A draft must not carry a {$key}.");
        }

        // Section A reports all three honestly as pending, and there is no input
        // for any of them, so a client cannot post one either.
        $html = $this->asCollege($college, $user)
            ->get(route('students.registration.step', ['step' => 1]))
            ->assertOk()
            ->getContent();

        foreach (['student_id', 'admission_number', 'roll_number'] as $key) {
            $this->assertStringContainsString('data-erp-identifier-value="'.$key.'"', $html);
        }
        $this->assertSame(3, substr_count($html, 'erp-id erp-id--pending'), 'All three identifiers are pending.');
        foreach (['student_number', 'admission_number', 'roll_number'] as $field) {
            $this->assertStringNotContainsString('name="'.$field.'"', $html);
        }

        // A hand-crafted POST cannot write one into the draft.
        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'student_number' => 'STU-HACK-0001',
            'admission_number' => 'ADM-HACK-0001',
            'roll_number' => '99',
        ])->assertRedirect();

        $step = $this->draftFor($college, $user)->stepData(1);
        $this->assertArrayNotHasKey('student_number', $step);
        $this->assertArrayNotHasKey('admission_number', $step);
        $this->assertArrayNotHasKey('roll_number', $step);
    }

    public function test_the_student_id_is_generated_once_on_submission_and_never_changes(): void
    {
        $college = $this->makeCollege('WIZ51');
        $user = $this->staff($college);
        $approver = $this->makeUserWithPermissions($college, ['students.view', 'students.create', 'approvals.view', 'approvals.approve']);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        $draft = $this->completeDraft($college, $user, [], [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
        ]);

        // Nothing has a Student ID yet.
        $this->assertFalse((bool) Student::withoutGlobalScopes()->where('college_id', $college->id)->exists());

        $this->asCollege($college, $user)->post(route('students.registration.submit'), [
            'draft_id' => $draft->id,
            'confirm_declaration' => '1',
        ])->assertRedirect(route('students.index'));

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->sole();
        $number = $student->student_number;

        $this->assertMatchesRegularExpression('/^STU-\d{4}-\d{4}$/', (string) $number);

        // Submitting twice must not mint a second number, and approval must not
        // replace the one already printed on the student's record.
        $this->asCollege($college, $user)->post(route('students.registration.submit'), [
            'draft_id' => $draft->id,
            'confirm_declaration' => '1',
        ])->assertRedirect(route('students.index'));

        $request = ApprovalRequest::withoutGlobalScopes()->where('target_id', $student->id)->sole();
        $this->asCollege($college, $approver)->post(route('approvals.approve', $request), [])->assertRedirect();

        $this->assertSame($number, $student->fresh()->student_number);
        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());

        // The identifier is read-only everywhere: step 1 shows the assigned value
        // once the student exists, still with no input to edit it.
        $identifiers = app(StudentIdentifierService::class)->forStudent($student->fresh(), $college->id);
        $this->assertSame($number, $identifiers['student_id']['value']);
        $this->assertSame('assigned', $identifiers['student_id']['state']);
        $this->assertSame('pending', $identifiers['roll_number']['state'], 'No section was chosen, so no roll number exists.');
    }

    public function test_roll_numbers_are_allocated_per_section_and_never_reused(): void
    {
        $college = $this->makeCollege('WIZ52');

        // This test drives the service directly instead of through HTTP, so it
        // must set the same tenant context a request would: every lookup inside
        // StudentService is college-scoped by CollegeScope, and dropping that
        // scope to make a fixture work would be a tenant-isolation regression.
        app(TenantContext::class)->set($college);

        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $sectionA = $this->makeSection($college, $year, $program, 'A');
        $sectionB = $this->makeSection($college, $year, $program, 'B');

        $service = app(StudentService::class);

        $enroll = function (Student $student, $section) use ($service, $college, $year, $program): StudentEnrollment {
            return $service->createEnrollment([
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'section_id' => $section->id,
            ], $college->id, $year->code);
        };

        $first = $enroll($this->makeStudent($college, ['first_name' => 'One']), $sectionA);
        $second = $enroll($this->makeStudent($college, ['first_name' => 'Two']), $sectionA);
        $otherSection = $enroll($this->makeStudent($college, ['first_name' => 'Three']), $sectionB);

        $this->assertSame('01', $first->roll_number);
        $this->assertSame('02', $second->roll_number);
        $this->assertSame('01', $otherSection->roll_number, 'A different section numbers its own list.');

        // An explicit office value inside the scope is kept as given.
        $explicit = $service->createEnrollment([
            'student_id' => $this->makeStudent($college, ['first_name' => 'Four'])->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'section_id' => $sectionA->id,
            'roll_number' => '25',
        ], $college->id, $year->code);

        $this->assertSame('25', $explicit->roll_number);

        // A clash inside the same scope is a field error and creates no row.
        $before = StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count();

        try {
            $service->createEnrollment([
                'student_id' => $this->makeStudent($college, ['first_name' => 'Five'])->id,
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'section_id' => $sectionA->id,
                'roll_number' => '25',
            ], $college->id, $year->code);

            $this->fail('A roll number already used in this section must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('roll_number', $exception->errors());
        }

        $this->assertSame($before, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());

        // The database refuses the duplicate too, even if the service is bypassed.
        $this->expectException(\Illuminate\Database\QueryException::class);

        StudentEnrollment::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_id' => $first->student_id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'section_id' => $sectionA->id,
            'roll_number' => '01',
            'enrollment_number' => 'ENR-DUPLICATE-ROLL',
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
        ]);
    }

    // -----------------------------------------------------------------
    // Part 4 — duplicate detection
    // -----------------------------------------------------------------

    public function test_a_matching_name_alone_is_never_reported_or_blocked(): void
    {
        $college = $this->makeCollege('WIZ53');
        $user = $this->staff($college);

        // Same name, nothing else in common — and no corroborating detail sent.
        $this->makeStudent($college, [
            'student_number' => 'STU-NAME-ONLY',
            'first_name' => 'Riya',
            'last_name' => 'Verma',
            'phone' => '9444444444',
        ]);

        $this->saveStep($college, $user, 1, ['first_name' => 'Riya', 'last_name' => 'Verma', 'phone' => '9111111111'])
            ->assertRedirect(route('students.registration.step', ['step' => 2]));

        $draft = $this->draftFor($college, $user);

        $this->assertSame([], (array) $draft->duplicate_warnings, 'A shared name is not a duplicate.');
        $this->assertSame('Riya', $draft->stepData(1)['first_name']);
    }

    public function test_a_name_with_a_corroborating_detail_is_a_warning_and_never_a_block(): void
    {
        $college = $this->makeCollege('WIZ54');
        $user = $this->staff($college);

        $existing = $this->makeStudent($college, [
            'student_number' => 'STU-SAME-FAMILY',
            'first_name' => 'Riya',
            'last_name' => 'Verma',
            'father_name' => 'Suresh Verma',
            'date_of_birth' => '2005-01-01',
            'phone' => '9444444444',
        ]);

        // Same name AND the same father's name: worth a human look, not a block.
        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'last_name' => 'Verma',
            'father_name' => 'Suresh Verma',
            'date_of_birth' => '2008-06-15',
            'phone' => '9111111111',
        ])->assertRedirect(route('students.registration.step', ['step' => 2]));

        $draft = $this->draftFor($college, $user);
        $warnings = (array) $draft->duplicate_warnings;

        $this->assertCount(1, $warnings);
        $this->assertSame((int) $existing->id, (int) $warnings[0]['student_id']);
        $this->assertSame('STU-SAME-FAMILY', $warnings[0]['student_number']);
        $this->assertContains("father's name", $warnings[0]['matched_on']);
        $this->assertStringContainsString('Check before approving', $warnings[0]['message']);

        // The warning is visible on the review screen and travels into the
        // approval request, where a human makes the decision.
        $html = $this->asCollege($college, $user)
            ->get(route('students.registration.step', ['step' => 4]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-erp-review-duplicates', $html);
        $this->assertStringContainsString('STU-SAME-FAMILY', $html);
    }

    public function test_duplicate_warnings_are_scoped_to_the_college(): void
    {
        $college = $this->makeCollege('WIZ55');
        $other = $this->makeCollege('WIZ55B');
        $user = $this->staff($college);

        // A namesake in a DIFFERENT college must never be reported.
        $this->makeStudent($other, [
            'student_number' => 'STU-OTHER-COLLEGE',
            'first_name' => 'Riya',
            'last_name' => 'Verma',
            'father_name' => 'Suresh Verma',
            'date_of_birth' => '2008-06-15',
            'phone' => '9444444444',
        ]);

        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'last_name' => 'Verma',
            'father_name' => 'Suresh Verma',
            'date_of_birth' => '2008-06-15',
            'phone' => '9111111111',
        ])->assertRedirect(route('students.registration.step', ['step' => 2]));

        $this->assertSame([], (array) $this->draftFor($college, $user)->duplicate_warnings);
    }

    /**
     * A mobile number shared inside one family is not an identity, but the
     * STUDENT's own number still is — the existing policy, unchanged.
     */
    public function test_a_shared_parent_mobile_is_allowed_while_the_students_own_number_still_blocks(): void
    {
        $college = $this->makeCollege('WIZ56');
        $user = $this->staff($college);

        $existing = $this->makeStudent($college, [
            'student_number' => 'STU-SIBLING',
            'first_name' => 'Aarav',
            'last_name' => 'Verma',
            'father_phone' => '9222222222',
            'phone' => '9444444444',
        ]);

        // The sibling shares the father's mobile — accepted (no father_phone rule).
        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'last_name' => 'Verma',
            'father_name' => 'Suresh Verma',
            'father_phone' => '9222222222',
            'phone' => '9111111111',
        ])->assertRedirect(route('students.registration.step', ['step' => 2]));

        $this->assertSame('9222222222', $this->draftFor($college, $user)->stepData(1)['father_phone']);

        // Reusing the student's own number is still refused, unchanged.
        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'last_name' => 'Verma',
            'phone' => '9444444444',
        ])->assertRedirect(route('students.registration.step', ['step' => 1]))
            ->assertSessionHasErrors(['phone']);

        $this->assertSame('9111111111', $this->draftFor($college, $user)->stepData(1)['phone'], 'The rejected save changed nothing.');
        $this->assertSame((int) $existing->id, (int) Student::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->where('phone', '9444444444')
            ->sole()->id);
    }

    public function test_an_apaar_id_already_recorded_blocks_a_new_registration_without_writing_anything(): void
    {
        $college = $this->makeCollege('WIZ57');
        $user = $this->staff($college);

        $this->makeStudent($college, [
            'student_number' => 'STU-APAAR',
            'first_name' => 'Aarav',
            'last_name' => 'Verma',
            'apaar_id' => '123456789012',
            'phone' => '9444444444',
        ]);

        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'last_name' => 'Verma',
            'apaar_id' => '123456789012',
            'phone' => '9111111111',
        ])->assertRedirect(route('students.registration.step', ['step' => 1]))
            ->assertSessionHasErrors(['apaar_id']);

        // Nothing was written: no draft, no student, no file.
        $this->assertSame(0, StudentRegistrationDraft::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->where('user_id', $user->id)
            ->count());
        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());

        // The same APAAR in another college is a different person's record.
        $other = $this->makeCollege('WIZ57B');
        $outsider = $this->staff($other);

        $this->saveStep($other, $outsider, 1, [
            'first_name' => 'Riya',
            'last_name' => 'Verma',
            'apaar_id' => '123456789012',
            'phone' => '9111111111',
        ])->assertRedirect(route('students.registration.step', ['step' => 2]));
    }

    // -----------------------------------------------------------------
    // Part 5 + 10 — four steps and old drafts
    // -----------------------------------------------------------------

    public function test_the_wizard_has_exactly_four_steps_and_rejects_a_fifth(): void
    {
        $this->assertSame([1, 2, 3, 4], array_keys(RegistrationStepRules::STEPS));
        $this->assertSame(
            ['Basic Information', 'Academic Record', 'Attach Documents', 'Final Review'],
            array_values(RegistrationStepRules::STEPS),
        );

        $college = $this->makeCollege('WIZ58');
        $user = $this->staff($college);

        $html = $this->stepOneHtml($college, $user);

        $this->assertStringContainsString('Step 1 of 4', $html);
        foreach (RegistrationStepRules::STEPS as $label) {
            $this->assertStringContainsString($label, $html, "The progress bar lists {$label}.");
        }
        $this->assertStringNotContainsString('Step 1 of 5', $html);

        // The old step count is gone from the routes as well.
        $this->asCollege($college, $user)
            ->get(route('students.registration.step', ['step' => 5]))
            ->assertNotFound();

        // Parent/Guardian is a section of step 1 now, never a step of its own.
        $this->saveStep($college, $user, 1, [
            'first_name' => 'Riya',
            'father_name' => 'Suresh Verma',
            'guardian_relation' => 'father',
        ])->assertRedirect(route('students.registration.step', ['step' => 2]));

        $step = $this->draftFor($college, $user)->stepData(1);
        $this->assertSame('Suresh Verma', $step['father_name']);
        $this->assertSame('father', $step['guardian_relation']);
    }

    public function test_a_four_step_draft_that_saved_the_review_step_is_not_treated_as_legacy(): void
    {
        $college = $this->makeCollege('WIZ59');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        // The current four-step shape: academic on 2, documents write nothing,
        // review on 4 (a `remarks` key is part of the NEW review step).
        $draft = StudentRegistrationDraft::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'user_id' => $user->id,
            'status' => StudentRegistrationDraft::STATUS_DRAFT,
            'current_step' => 4,
            'payload' => [
                2 => ['academic_year_id' => $year->id, 'program_id' => $program->id, 'semester' => '1'],
                4 => ['remarks' => null],
            ],
        ]);

        $this->assertFalse($draft->hasLegacyPayload(), 'A current payload is never re-mapped.');
        $this->assertSame($year->id, (int) $draft->stepData(2)['academic_year_id']);
        $this->assertSame([], $draft->stepData(1));
        $this->assertSame(4, $draft->currentStep());

        // Saving step 1 afterwards keeps the academic record where it is.
        $this->saveStep($college, $user, 1, ['first_name' => 'Riya'], draft: true)->assertRedirect();

        $fresh = $draft->fresh();
        $this->assertSame('Riya', $fresh->stepData(1)['first_name']);
        $this->assertSame($year->id, (int) $fresh->stepData(2)['academic_year_id']);
        $this->assertFalse($fresh->hasLegacyPayload());
    }

    /**
     * The wizard advances `current_step` on a SAVE, never on a read: opening a
     * step is a GET and must leave the draft exactly where it was. This is the
     * invariant that makes "resume where the applicant actually is" meaningful.
     */
    public function test_reading_a_step_never_moves_the_resume_point(): void
    {
        $college = $this->makeCollege('WIZ61');
        $user = $this->staff($college);

        $this->asCollege($college, $user)->get(route('students.registration.create'))->assertRedirect();

        $draft = $this->draftFor($college, $user);

        $this->assertSame(1, (int) $draft->current_step);
        $this->assertSame(1, $draft->currentStep());

        // Every step can be opened without writing anything.
        foreach ([1, 2, 3, 4] as $step) {
            $this->asCollege($college, $user)
                ->get(route('students.registration.step', ['step' => $step]))
                ->assertOk();
        }

        $draft->refresh();

        $this->assertSame(1, (int) $draft->current_step, 'A step read must not advance the draft.');
        $this->assertSame(1, $draft->currentStep());
        $this->assertSame([], $draft->mergedData(), 'A step read must not store a payload.');

        // Saving the review step is a write, and THAT parks the draft on step 4.
        $this->saveStep($college, $user, 4, ['remarks' => 'Reviewed with the office.'], draft: true)
            ->assertRedirect(route('students.registration.step', ['step' => 4]));

        $this->assertSame(4, $this->draftFor($college, $user)->currentStep());

        $this->asCollege($college, $user)
            ->get(route('students.registration.create'))
            ->assertRedirect(route('students.registration.step', ['step' => 4]));
    }

    /**
     * The step mapping is the single source of truth for draft resumption, so it
     * is pinned directly: a CURRENT payload keeps its own step number, and only
     * a genuinely legacy payload is translated (old 1+2 → 1, 3 → 2, 4 → 3,
     * 5 → 4).
     */
    public function test_draft_step_mapping_clamps_current_steps_and_translates_only_legacy_ones(): void
    {
        $current = [
            1 => ['first_name' => 'Riya'],
            2 => ['academic_year_id' => 1, 'program_id' => 1],
        ];

        $this->assertFalse(RegistrationDraftUpgrade::isLegacyPayload($current));

        // A current draft resumes exactly where it was parked — including steps
        // 2 and 4, which the legacy map would drag back to 1 and 3.
        foreach ([1, 2, 3, 4] as $step) {
            $this->assertSame(
                $step,
                RegistrationDraftUpgrade::resumeStep($current, $step),
                "A current four-step payload parked on step {$step} must resume there.",
            );
        }

        // Out-of-range markers are clamped into the wizard, never mapped.
        $this->assertSame(1, RegistrationDraftUpgrade::resumeStep($current, 0));
        $this->assertSame(4, RegistrationDraftUpgrade::resumeStep($current, 9));

        $legacy = [
            1 => ['first_name' => 'Aarav'],
            2 => ['father_name' => 'Rajesh Shah'],
            3 => ['academic_year_id' => 1, 'program_id' => 1],
        ];

        $this->assertTrue(RegistrationDraftUpgrade::isLegacyPayload($legacy));

        $this->assertSame(1, RegistrationDraftUpgrade::resumeStep($legacy, 1));
        $this->assertSame(1, RegistrationDraftUpgrade::resumeStep($legacy, 2), 'Old Parent/Guardian → step 1.');
        $this->assertSame(2, RegistrationDraftUpgrade::resumeStep($legacy, 3), 'Old Academic → step 2.');
        $this->assertSame(3, RegistrationDraftUpgrade::resumeStep($legacy + [4 => []], 4), 'Old Documents → step 3.');
        $this->assertSame(
            4,
            RegistrationDraftUpgrade::resumeStep($legacy + [5 => ['remarks' => 'old note']], 5),
            'Old Review → step 4.',
        );

        // A legacy draft that says "academic" but holds no academic data yet
        // resumes on step 1 instead of on an empty step 2.
        $this->assertSame(1, RegistrationDraftUpgrade::resumeStep([2 => ['father_name' => 'Rajesh Shah']], 3));
    }

    public function test_an_old_five_step_draft_resumes_in_the_four_step_wizard(): void
    {
        Storage::fake('private');
        $college = $this->makeCollege('WIZ60');
        $user = $this->staff($college);
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);

        // An untouched five-step payload, exactly as the old wizard wrote it: the
        // applicant was parked on step 3 (Academic Record).
        $draft = StudentRegistrationDraft::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'user_id' => $user->id,
            'status' => StudentRegistrationDraft::STATUS_DRAFT,
            'current_step' => 3,
            'payload' => [
                1 => ['first_name' => 'Aarav', 'last_name' => 'Shah', 'phone' => '9111111111', 'email' => 'aarav@example.test'],
                2 => ['father_name' => 'Rajesh Shah', 'father_phone' => '9222222222', 'guardian_relation' => 'father', 'guardian_name' => 'Rajesh Shah'],
                3 => [
                    'academic_year_id' => $year->id,
                    'program_id' => $program->id,
                    'semester' => '1',
                    'qualifications' => [['level' => 'class_10', 'examination_year' => 2022]],
                ],
                4 => [],
                5 => ['remarks' => 'old review remark'],
            ],
        ]);

        $this->assertTrue($draft->hasLegacyPayload());

        // Resuming maps old 3 (academic) → new 2, and starts no student.
        $this->asCollege($college, $user)->get(route('students.registration.create'))
            ->assertRedirect(route('students.registration.step', ['step' => 2]));

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(1, StudentRegistrationDraft::withoutGlobalScopes()->where('college_id', $college->id)->count());

        $fresh = $draft->fresh();
        $this->assertSame('Aarav', $fresh->stepData(1)['first_name']);
        $this->assertSame('Rajesh Shah', $fresh->stepData(1)['father_name'], 'Parent data moved into step 1.');
        $this->assertSame($year->id, (int) $fresh->stepData(2)['academic_year_id']);
        $this->assertCount(1, $fresh->stepData(2)['qualifications']);

        // The forms render the mapped data on the right pages.
        $this->asCollege($college, $user)->get(route('students.registration.step', ['step' => 1]))
            ->assertOk()
            ->assertSee('Aarav')
            ->assertSee('Rajesh Shah');

        $this->asCollege($college, $user)->get(route('students.registration.step', ['step' => 2]))
            ->assertOk()
            ->assertSee((string) $year->name);

        // The next save converges the stored JSON to the new shape, and nothing
        // is lost in the process.
        $this->saveStep($college, $user, 2, [
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'semester' => '1',
        ], draft: true)->assertRedirect();

        $converged = $draft->fresh();
        $this->assertFalse($converged->hasLegacyPayload());
        $this->assertSame('Rajesh Shah', $converged->stepData(1)['father_name']);
        $this->assertSame('Aarav', $converged->stepData(1)['first_name']);
        $this->assertSame($year->id, (int) $converged->stepData(2)['academic_year_id']);
        $this->assertSame(1, StudentRegistrationDraft::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }
}
