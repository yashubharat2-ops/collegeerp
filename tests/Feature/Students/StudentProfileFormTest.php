<?php

namespace Tests\Feature\Students;

use App\Domain\Student\Support\Aadhaar;
use App\Models\AuditLog;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Student Create/Edit form: the seven sections, the identity handling that
 * sits behind them, and the tenancy/authorization rules that must survive it.
 *
 * Only the shapes the form actually promises are asserted — that the sections
 * render, that what is submitted is what is stored, that an Aadhaar number is
 * never readable after it is saved, and that the existing Student guarantees
 * (tenant scope, server-generated number, policies) still hold.
 */
class StudentProfileFormTest extends TestCase
{
    use StudentTestHelpers;

    /** Verhoeff-valid fixtures (see Tests\Unit\Students\AadhaarTest). */
    private const AADHAAR = '999941057058';
    private const OTHER_AADHAAR = '222233334444';

    /** A real 1×1 JPEG, so the file rules see an actual image and not a renamed blob. */
    private const ONE_PIXEL_JPEG = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Riya',
            'middle_name' => 'S',
            'last_name' => 'Verma',
            'email' => 'riya@example.test',
            'phone' => '9999999999',
            'alternate_phone' => '8888888888',
            'gender' => 'female',
            'category' => 'obc',
            'date_of_birth' => '2008-06-15',
            'admission_date' => '2026-06-10',
            'status' => 'active',

            // Parent / guardian
            'father_name' => 'Ramesh Verma',
            'mother_name' => 'Sunita Verma',
            'guardian_name' => 'Ramesh Verma',
            'guardian_relation' => 'father',
            'guardian_phone' => '7777777777',
            'guardian_email' => 'ramesh@example.test',
            'guardian_occupation' => 'Farmer',
            'guardian_address' => 'Village Kheri, Dist. Sitapur',

            // Contact
            'emergency_contact_name' => 'Sunita Verma',
            'emergency_contact_phone' => '6666666666',
            'address_line_1' => '12 Station Road',
            'address_line_2' => 'Near the bus stand',
            'city' => 'Sitapur',
            'state' => 'Uttar Pradesh',
            'postal_code' => '261001',
            'country' => 'India',

            // Academic / admission snapshot
            'previous_school_name' => 'Sitapur Public School',
            'previous_school_board' => 'CBSE',
            'previous_qualification' => 'Class XII',
            'previous_exam_year' => '2026',
            'previous_percentage' => '82.40',

            // Additional information
            'blood_group' => 'B+',
            'nationality' => 'Indian',
            'mother_tongue' => 'Hindi',
            'remarks' => 'Day scholar',
        ], $overrides);
    }

    private function realJpeg(string $name = 'portrait.jpg'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'student-photo').'.jpg';
        file_put_contents($path, base64_decode(self::ONE_PIXEL_JPEG));

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    // ---------------------------------------------------------------------
    // Sections
    // ---------------------------------------------------------------------

    public function test_the_create_form_renders_every_requested_section_in_order(): void
    {
        $college = $this->makeCollege('SPFC');
        $admin = $this->makeUserWithPermissions($college, ['students.create', 'student_enrollments.create']);

        // The optional first-enrollment block renders only when the college has
        // an academic year to enroll into.
        $this->makeYear($college);
        $this->makeProgram($college);

        $response = $this->asCollege($college, $admin)->get(route('students.create'))->assertOk();

        $response->assertSee('enctype="multipart/form-data"', false);

        foreach ([
            'Academic / admission',
            'Basic information',
            'Government / identity',
            'Parent / guardian',
            'Contact',
            'Additional information',
            'First enrollment (optional)',
            'Documents',
        ] as $section) {
            $response->assertSee($section, false);
        }

        // The numbered cards must appear in the documented chronological order:
        // Academic first, then Basic, then Parent/guardian, Contact, Additional
        // and finally Documents.
        $html = $response->getContent();
        $positions = array_map(
            fn (string $heading) => strpos($html, $heading),
            ['Academic / admission', 'Basic information', 'Parent / guardian', 'Contact', 'Additional information', 'Documents'],
        );

        $this->assertNotContains(false, $positions, 'Every section heading must be rendered.');
        $this->assertSame($positions, array_values(array_unique($positions)), 'No section may be rendered twice.');
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'The sections must be rendered in chronological order.');

        // Government / identity is a SUB-BLOCK of Basic information: it must
        // appear inside it (after it) and never as its own section.
        $government = strpos($html, 'Government / identity');
        $this->assertNotFalse($government);
        $this->assertGreaterThan($positions[1], $government, 'Government / identity must sit inside Basic information.');
        $this->assertLessThan($positions[2], $government, 'Government / identity must sit before Parent / guardian.');
        $response->assertDontSee('Identity &amp; government IDs', false);
    }

    public function test_the_academic_section_renders_its_fields_in_chronological_order(): void
    {
        $college = $this->makeCollege('SPFORD');
        $admin = $this->makeUserWithPermissions($college, [
            'students.view', 'students.create', 'students.update', 'student_enrollments.create',
        ]);

        // Same precondition as above: the optional first-enrollment block only
        // renders when the college has an academic year to enroll into.
        $this->makeYear($college);
        $this->makeProgram($college);

        // Create: academic year · program/course · section/batch · admission date
        // · student status · enrollment date · previous education.
        $create = $this->asCollege($college, $admin)->get(route('students.create'))->assertOk()->getContent();

        $this->assertFieldsInOrder($this->academicSection($create), [
            'for="academic_year_id"',
            'for="program_id"',
            'for="section_id"',
            'for="admission_date"',
            'for="status"',
            'for="enrollment_date"',
            'for="previous_school_name"',
        ], 'The create form must order the academic fields by admission chronology.');

        // Edit renders the same partial — and therefore the same order — with the
        // optional first-enrollment block absent (an existing enrollment is
        // managed by the Enrollment module, not from here).
        $student = $this->makeStudent($college);

        $edit = $this->asCollege($college, $admin)->get(route('students.edit', $student))->assertOk()->getContent();
        $editSection = $this->academicSection($edit);

        $this->assertFieldsInOrder($editSection, [
            'for="admission_date"',
            'for="status"',
            'for="previous_school_name"',
        ], 'The edit form must keep the same academic order.');

        $this->assertStringNotContainsString('for="enrollment_date"', $editSection, 'The first-enrollment block stays create-only.');
    }

    /** The rendered markup of the 01 Academic / admission card, for order assertions. */
    private function academicSection(string $html): string
    {
        $start = strpos($html, 'Academic / admission');
        $end = strpos($html, 'Basic information');

        $this->assertNotFalse($start, 'The Academic / admission section must be rendered.');
        $this->assertNotFalse($end, 'The Basic information section must be rendered.');

        return substr($html, $start, $end - $start);
    }

    /**
     * Assert the markers are all rendered and appear in the given order.
     *
     * @param  array<int, string>  $markers
     */
    private function assertFieldsInOrder(string $html, array $markers, string $message): void
    {
        $positions = array_map(fn (string $marker) => strpos($html, $marker), $markers);

        $this->assertNotContains(false, $positions, $message.' Every field must be rendered.');
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, $message);
    }

    public function test_the_edit_form_masks_identity_numbers_and_links_to_the_document_module(): void
    {
        $college = $this->makeCollege('SPFE');
        $admin = $this->makeUserWithPermissions($college, [
            'students.view', 'students.update', 'student_documents.view', 'student_documents.create',
        ]);

        $student = $this->makeStudent($college, [
            'aadhaar_number' => self::AADHAAR,
            'aadhaar_last4' => '7058',
            'aadhaar_hash' => Aadhaar::hash(self::AADHAAR),
            'govt_id_type' => 'pan',
            'govt_id_number' => 'ABCDE1234F',
        ]);

        $response = $this->asCollege($college, $admin)->get(route('students.edit', $student))->assertOk();

        $response->assertSee('XXXX XXXX 7058', false)
            ->assertSee('XXXXXX234F', false)
            ->assertSee(route('student-documents.create', ['student_id' => $student->id]), false)
            ->assertSee('Stored documents:', false);
    }

    // ---------------------------------------------------------------------
    // Create
    // ---------------------------------------------------------------------

    public function test_creating_a_student_persists_every_profile_section(): void
    {
        $college = $this->makeCollege('SPF1');
        $admin = $this->makeUserWithPermissions($college, ['students.view', 'students.create']);

        $this->asCollege($college, $admin)
            ->post(route('students.store'), $this->payload([
                'aadhaar_number' => '9999 4105 7058',   // separators are accepted
                'apaar_id' => '123456789012',
                'govt_id_type' => 'pan',
                'govt_id_number' => 'ABCDE1234F',
            ]))
            ->assertSessionHasNoErrors();

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();

        // Basic + parent/guardian + contact + academic + additional
        $this->assertSame('Ramesh Verma', $student->father_name);
        $this->assertSame('Sunita Verma', $student->mother_name);
        $this->assertSame('father', $student->guardian_relation);
        $this->assertSame('7777777777', $student->guardian_phone);
        $this->assertSame('ramesh@example.test', $student->guardian_email);
        $this->assertSame('Farmer', $student->guardian_occupation);
        $this->assertSame('Sunita Verma', $student->emergency_contact_name);
        $this->assertSame('6666666666', $student->emergency_contact_phone);
        $this->assertSame('Sitapur', $student->city);
        $this->assertSame('CBSE', $student->previous_school_board);
        $this->assertSame('Class XII', $student->previous_qualification);
        $this->assertSame(2026, $student->previous_exam_year);
        $this->assertSame(82.4, (float) $student->previous_percentage);
        $this->assertSame('B+', $student->blood_group);
        $this->assertSame('Hindi', $student->mother_tongue);
        $this->assertSame('Day scholar', $student->remarks);

        // Identity: canonical digits, derived tail + digest, decrypted on read.
        $this->assertSame(self::AADHAAR, $student->aadhaar_number);
        $this->assertSame('7058', $student->aadhaar_last4);
        $this->assertSame(Aadhaar::hash(self::AADHAAR), $student->aadhaar_hash);
        $this->assertSame('ABCDE1234F', $student->govt_id_number);
        $this->assertTrue($student->hasAadhaar());
    }

    public function test_the_aadhaar_number_is_encrypted_at_rest_and_derived_server_side(): void
    {
        $college = $this->makeCollege('SPF2');
        $admin = $this->makeUserWithPermissions($college, ['students.create']);

        $this->asCollege($college, $admin)
            ->post(route('students.store'), $this->payload([
                'aadhaar_number' => self::AADHAAR,
                // Browser-supplied derived columns must be ignored, not stored.
                'aadhaar_last4' => '0000',
                'aadhaar_hash' => 'forged-digest',
            ]))
            ->assertSessionHasNoErrors();

        $row = DB::table('students')->where('college_id', $college->id)->first();

        $this->assertNotSame(self::AADHAAR, $row->aadhaar_number, 'The Aadhaar number must never be stored in clear text.');
        $this->assertSame(self::AADHAAR, Crypt::decryptString($row->aadhaar_number));
        $this->assertSame('7058', $row->aadhaar_last4);
        $this->assertSame(Aadhaar::hash(self::AADHAAR), $row->aadhaar_hash);
    }

    public function test_the_full_aadhaar_number_is_never_rendered_on_the_profile_or_the_edit_form(): void
    {
        $college = $this->makeCollege('SPF3');
        $admin = $this->makeUserWithPermissions($college, ['students.view', 'students.update']);
        $student = $this->makeStudent($college, [
            'aadhaar_number' => self::AADHAAR,
            'aadhaar_last4' => '7058',
            'aadhaar_hash' => Aadhaar::hash(self::AADHAAR),
        ]);

        $profile = $this->asCollege($college, $admin)->get(route('students.show', $student))->assertOk()->getContent();
        $this->assertStringNotContainsString(self::AADHAAR, $profile);
        $this->assertStringNotContainsString('9999 4105 7058', $profile);
        $this->assertStringContainsString('XXXX XXXX 7058', $profile);

        $form = $this->asCollege($college, $admin)->get(route('students.edit', $student))->assertOk()->getContent();
        $this->assertStringNotContainsString(self::AADHAAR, $form);
        $this->assertStringContainsString('XXXX XXXX 7058', $form);
        $this->assertStringContainsString('Leave blank to keep the stored number', $form);
    }

    public function test_the_serialised_student_never_exposes_identity_columns(): void
    {
        $college = $this->makeCollege('SPF4');
        $student = $this->makeStudent($college, [
            'aadhaar_number' => self::AADHAAR,
            'aadhaar_last4' => '7058',
            'aadhaar_hash' => Aadhaar::hash(self::AADHAAR),
            'govt_id_type' => 'pan',
            'govt_id_number' => 'ABCDE1234F',
        ]);

        $serialised = $student->toJson();

        $this->assertStringNotContainsString(self::AADHAAR, $serialised);
        $this->assertStringNotContainsString('ABCDE1234F', $serialised);
        $this->assertStringNotContainsString(Aadhaar::hash(self::AADHAAR), $serialised);
    }

    public function test_the_audit_log_records_the_masked_tail_but_never_the_full_number(): void
    {
        $college = $this->makeCollege('SPF5');
        $admin = $this->makeUserWithPermissions($college, ['students.create']);

        $this->asCollege($college, $admin)
            ->post(route('students.store'), $this->payload(['aadhaar_number' => self::AADHAAR]))
            ->assertSessionHasNoErrors();

        $audit = AuditLog::query()->where('action', 'student.created')->latest('id')->firstOrFail();
        $recorded = json_encode($audit->new_values);

        $this->assertStringNotContainsString(self::AADHAAR, $recorded);
        $this->assertArrayNotHasKey('aadhaar_number', $audit->new_values);
        $this->assertArrayNotHasKey('aadhaar_hash', $audit->new_values);
        $this->assertArrayNotHasKey('govt_id_number', $audit->new_values);
        $this->assertSame('7058', $audit->new_values['aadhaar_last4']);
    }

    // ---------------------------------------------------------------------
    // Identity rules
    // ---------------------------------------------------------------------

    public function test_an_aadhaar_number_that_fails_the_checksum_is_rejected(): void
    {
        $college = $this->makeCollege('SPF6');
        $admin = $this->makeUserWithPermissions($college, ['students.create']);

        foreach (['123456789012', '99994105705', '099941057058'] as $invalid) {
            $this->asCollege($college, $admin)
                ->post(route('students.store'), $this->payload(['aadhaar_number' => $invalid]))
                ->assertSessionHasErrors('aadhaar_number');
        }

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_an_aadhaar_number_can_only_be_recorded_once_per_college(): void
    {
        $collegeA = $this->makeCollege('SPF7A');
        $collegeB = $this->makeCollege('SPF7B');
        $adminA = $this->makeUserWithPermissions($collegeA, ['students.create']);
        $adminB = $this->makeUserWithPermissions($collegeB, ['students.create']);

        $this->asCollege($collegeA, $adminA)
            ->post(route('students.store'), $this->payload(['aadhaar_number' => self::AADHAAR]))
            ->assertSessionHasNoErrors();

        $this->asCollege($collegeA, $adminA)
            ->post(route('students.store'), $this->payload(['aadhaar_number' => self::AADHAAR, 'phone' => '9000000001']))
            ->assertSessionHasErrors('aadhaar_number');

        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());

        // The duplicate lookup is college-scoped: another college's rows are
        // never read, so the same number is accepted there.
        $this->asCollege($collegeB, $adminB)
            ->post(route('students.store'), $this->payload(['aadhaar_number' => self::AADHAAR]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $collegeB->id)->count());
    }

    public function test_a_removed_student_does_not_block_the_same_aadhaar_being_registered_again(): void
    {
        $college = $this->makeCollege('SPF8');
        $admin = $this->makeUserWithPermissions($college, ['students.create', 'students.delete']);

        $student = $this->makeStudent($college, [
            'aadhaar_number' => self::AADHAAR,
            'aadhaar_last4' => '7058',
            'aadhaar_hash' => Aadhaar::hash(self::AADHAAR),
        ]);

        $student->delete();

        $this->asCollege($college, $admin)
            ->post(route('students.store'), $this->payload(['aadhaar_number' => self::AADHAAR]))
            ->assertSessionHasNoErrors();

        // The removed row is still on disk (soft delete) and the new one was
        // created: exactly one LIVE student now holds that Aadhaar number.
        $this->assertSame(2, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $college->id)->whereNull('deleted_at')->count());
    }

    public function test_profile_fields_are_validated(): void
    {
        $college = $this->makeCollege('SPF9');
        $admin = $this->makeUserWithPermissions($college, ['students.create']);

        $invalid = [
            'guardian_email' => 'not-an-email',
            'guardian_relation' => 'cousin',
            'apaar_id' => 'not-12-digits',
            'govt_id_type' => 'aadhaar',                  // not an offered ID type
            'previous_percentage' => '150',
            'previous_exam_year' => '1700',
            'blood_group' => 'ZZ',
            'photo' => UploadedFile::fake()->create('notes.txt', 5, 'text/plain'),
        ];

        foreach ($invalid as $field => $value) {
            $this->asCollege($college, $admin)
                ->post(route('students.store'), $this->payload([$field => $value]))
                ->assertSessionHasErrors($field);
        }

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());

        // A government ID is a type + number pair: a type on its own is
        // rejected while nothing is stored yet.
        $this->asCollege($college, $admin)
            ->post(route('students.store'), $this->payload(['govt_id_type' => 'pan']))
            ->assertSessionHasErrors('govt_id_number');

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    // ---------------------------------------------------------------------
    // Update
    // ---------------------------------------------------------------------

    public function test_editing_keeps_a_blank_aadhaar_replaces_a_supplied_one_and_can_remove_it(): void
    {
        $college = $this->makeCollege('SPF10');
        $admin = $this->makeUserWithPermissions($college, ['students.view', 'students.update']);

        $student = $this->makeStudent($college, [
            'aadhaar_number' => self::AADHAAR,
            'aadhaar_last4' => '7058',
            'aadhaar_hash' => Aadhaar::hash(self::AADHAAR),
        ]);

        // 1. A blank field keeps the stored number (the form never echoes it).
        $this->asCollege($college, $admin)
            ->put(route('students.update', $student), $this->payload(['first_name' => 'Renamed']), ['Referer' => route('students.edit', $student)])
            ->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertSame('Renamed', $student->first_name);
        $this->assertSame(self::AADHAAR, $student->aadhaar_number);
        $this->assertSame('7058', $student->aadhaar_last4);

        // 2. A supplied number replaces it and rebuilds the tail + digest.
        $this->asCollege($college, $admin)
            ->put(route('students.update', $student), $this->payload(['aadhaar_number' => self::OTHER_AADHAAR]), ['Referer' => route('students.edit', $student)])
            ->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertSame(self::OTHER_AADHAAR, $student->aadhaar_number);
        $this->assertSame('4444', $student->aadhaar_last4);
        $this->assertSame(Aadhaar::hash(self::OTHER_AADHAAR), $student->aadhaar_hash);

        // 3. An explicit removal clears the number, the tail and the digest.
        $this->asCollege($college, $admin)
            ->put(route('students.update', $student), $this->payload(['remove_aadhaar' => '1']), ['Referer' => route('students.edit', $student)])
            ->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertNull($student->aadhaar_number);
        $this->assertNull($student->aadhaar_last4);
        $this->assertNull($student->aadhaar_hash);
        $this->assertFalse($student->hasAadhaar());
    }

    public function test_editing_can_replace_and_remove_the_other_government_id(): void
    {
        $college = $this->makeCollege('SPF11');
        $admin = $this->makeUserWithPermissions($college, ['students.update']);

        $student = $this->makeStudent($college, [
            'govt_id_type' => 'pan',
            'govt_id_number' => 'ABCDE1234F',
        ]);

        $this->asCollege($college, $admin)
            ->put(route('students.update', $student), $this->payload([
                'govt_id_type' => 'passport',
                'govt_id_number' => 'P1234567',
            ]), ['Referer' => route('students.edit', $student)])
            ->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertSame('passport', $student->govt_id_type);
        $this->assertSame('P1234567', $student->govt_id_number);

        // A blank number keeps the stored one (the form never echoes it), so the
        // type can be corrected on its own.
        $this->asCollege($college, $admin)
            ->put(route('students.update', $student), $this->payload(['govt_id_type' => 'pan']), ['Referer' => route('students.edit', $student)])
            ->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertSame('pan', $student->govt_id_type);
        $this->assertSame('P1234567', $student->govt_id_number);

        // Choosing no type clears the whole pair (a number without a type is not
        // a government ID), exactly like the explicit remove flag.
        $this->asCollege($college, $admin)
            ->put(route('students.update', $student), $this->payload(['govt_id_type' => '']), ['Referer' => route('students.edit', $student)])
            ->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertNull($student->govt_id_type);
        $this->assertNull($student->govt_id_number);

        $this->asCollege($college, $admin)
            ->put(route('students.update', $student), $this->payload(['remove_govt_id' => '1']), ['Referer' => route('students.edit', $student)])
            ->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertNull($student->govt_id_type);
        $this->assertNull($student->govt_id_number);
    }

    public function test_update_still_ignores_tenant_and_server_owned_fields(): void
    {
        $college = $this->makeCollege('SPF12');
        $other = $this->makeCollege('SPF12B');
        $admin = $this->makeUserWithPermissions($college, ['students.view', 'students.update']);

        $student = $this->makeStudent($college, ['student_number' => 'STU-LOCKED']);

        $this->asCollege($college, $admin)
            ->put(route('students.update', $student), $this->payload([
                'student_number' => 'HACKED-1',
                'college_id' => $other->id,
                'admission_application_id' => 4242,
                'photo_path' => 'students/1/photos/forged.jpg',
                'first_name' => 'Still Here',
            ]), ['Referer' => route('students.edit', $student)])
            ->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertSame('STU-LOCKED', $student->student_number);
        $this->assertSame($college->id, $student->college_id);
        $this->assertNull($student->admission_application_id);
        $this->assertNull($student->photo_path);
        $this->assertSame('Still Here', $student->first_name);
    }

    public function test_a_cross_college_student_cannot_be_edited_through_the_form(): void
    {
        $collegeA = $this->makeCollege('SPF13A');
        $collegeB = $this->makeCollege('SPF13B');
        $adminA = $this->makeUserWithPermissions($collegeA, ['students.update']);

        $foreign = $this->makeStudent($collegeB, ['first_name' => 'Foreign']);

        $this->asCollege($collegeA, $adminA)
            ->get(route('students.edit', $foreign))
            ->assertNotFound();

        $this->asCollege($collegeA, $adminA)
            ->put(route('students.update', $foreign), $this->payload())
            ->assertNotFound();

        $this->assertSame('Foreign', $foreign->refresh()->first_name);
    }

    // ---------------------------------------------------------------------
    // Photo
    // ---------------------------------------------------------------------

    public function test_a_portrait_is_stored_privately_served_inline_and_can_be_replaced_or_removed(): void
    {
        Storage::fake('private');

        $college = $this->makeCollege('SPF14');
        $admin = $this->makeUserWithPermissions($college, ['students.view', 'students.create', 'students.update']);

        $this->asCollege($college, $admin)
            ->post(route('students.store'), $this->payload(['photo' => $this->realJpeg()]))
            ->assertSessionHasNoErrors();

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();

        $this->assertNotNull($student->photo_path);
        $this->assertStringStartsWith('students/'.$college->id.'/photos/', $student->photo_path);
        Storage::disk('private')->assertExists($student->photo_path);

        // Served inline: an <img src> cannot render an attachment response.
        $response = $this->asCollege($college, $admin)->get(route('students.photo', $student))->assertOk();
        $this->assertStringContainsString('inline', (string) $response->headers->get('content-disposition'));
        $this->assertStringNotContainsString('attachment', (string) $response->headers->get('content-disposition'));

        // Replacing the portrait keeps exactly one blob: the old one is gone.
        $first = $student->photo_path;

        $this->asCollege($college, $admin)
            ->put(route('students.update', $student), $this->payload(['photo' => $this->realJpeg('new.jpg')]), ['Referer' => route('students.edit', $student)])
            ->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertNotSame($first, $student->photo_path);
        Storage::disk('private')->assertMissing($first);
        Storage::disk('private')->assertExists($student->photo_path);

        // Removing clears both the column and the file.
        $second = $student->photo_path;

        $this->asCollege($college, $admin)
            ->put(route('students.update', $student), $this->payload(['remove_photo' => '1']), ['Referer' => route('students.edit', $student)])
            ->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertNull($student->photo_path);
        Storage::disk('private')->assertMissing($second);
        $this->asCollege($college, $admin)->get(route('students.photo', $student))->assertNotFound();
    }

    public function test_a_non_image_upload_is_rejected_as_a_photograph(): void
    {
        Storage::fake('private');

        $college = $this->makeCollege('SPF15');
        $admin = $this->makeUserWithPermissions($college, ['students.create']);

        $this->asCollege($college, $admin)
            ->post(route('students.store'), $this->payload([
                'photo' => UploadedFile::fake()->create('payload.php', 10, 'text/x-php'),
            ]))
            ->assertSessionHasErrors('photo');

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    // ---------------------------------------------------------------------
    // First enrollment
    // ---------------------------------------------------------------------

    public function test_the_optional_first_enrollment_is_created_with_the_student(): void
    {
        $college = $this->makeCollege('SPF16');
        $admin = $this->makeUserWithPermissions($college, ['students.view', 'students.create', 'student_enrollments.create']);

        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $section = $this->makeSection($college, $year, $program);

        $this->asCollege($college, $admin)
            ->post(route('students.store'), $this->payload([
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'section_id' => $section->id,
                'enrollment_date' => '2026-06-12',
            ]))
            ->assertSessionHasNoErrors();

        $student = Student::withoutGlobalScopes()->where('college_id', $college->id)->firstOrFail();
        $enrollment = StudentEnrollment::withoutGlobalScopes()->where('student_id', $student->id)->firstOrFail();

        $this->assertSame($college->id, $enrollment->college_id);
        $this->assertSame($year->id, $enrollment->academic_year_id);
        $this->assertSame($program->id, $enrollment->program_id);
        $this->assertSame($section->id, $enrollment->section_id);
        $this->assertSame('active', $enrollment->status);
        $this->assertNotSame('', (string) $enrollment->enrollment_number);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student_enrollment.created', 'subject_id' => $enrollment->id]);
    }

    public function test_a_student_without_an_enrollment_is_still_created(): void
    {
        $college = $this->makeCollege('SPF17');
        $admin = $this->makeUserWithPermissions($college, ['students.create', 'student_enrollments.create']);

        $this->makeYear($college);

        $this->asCollege($college, $admin)->post(route('students.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_the_enrollment_block_requires_the_enrollment_permission(): void
    {
        $college = $this->makeCollege('SPF18');
        $admin = $this->makeUserWithPermissions($college, ['students.create']);   // deliberately no student_enrollments.create

        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $section = $this->makeSection($college, $year, $program);

        $this->asCollege($college, $admin)
            ->get(route('students.create'))
            ->assertOk()
            ->assertDontSee('First enrollment (optional)', false);

        $this->asCollege($college, $admin)
            ->post(route('students.store'), $this->payload([
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'section_id' => $section->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_a_section_from_another_year_rejects_the_whole_save(): void
    {
        $college = $this->makeCollege('SPF19');
        $admin = $this->makeUserWithPermissions($college, ['students.create', 'student_enrollments.create']);

        $year = $this->makeYear($college);
        $otherYear = $this->makeNextYear($college);
        $program = $this->makeProgram($college);
        $foreignSection = $this->makeSection($college, $otherYear, $program);

        $this->asCollege($college, $admin)
            ->post(route('students.store'), $this->payload([
                'academic_year_id' => $year->id,
                'program_id' => $program->id,
                'section_id' => $foreignSection->id,
            ]))
            ->assertSessionHasErrors('section_id');

        // The enrollment failed, so the student must not survive either.
        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $college->id)->count());
        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->count());
    }

    public function test_a_cross_college_academic_year_cannot_be_attached(): void
    {
        $collegeA = $this->makeCollege('SPF20A');
        $collegeB = $this->makeCollege('SPF20B');
        $adminA = $this->makeUserWithPermissions($collegeA, ['students.create', 'student_enrollments.create']);

        $foreignYear = $this->makeYear($collegeB, '2026', '2026-27');

        $this->asCollege($collegeA, $adminA)
            ->post(route('students.store'), $this->payload(['academic_year_id' => $foreignYear->id]))
            ->assertSessionHasErrors('academic_year_id');

        $this->assertSame(0, Student::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
        $this->assertSame(0, StudentEnrollment::withoutGlobalScopes()->where('college_id', $collegeA->id)->count());
    }
}
