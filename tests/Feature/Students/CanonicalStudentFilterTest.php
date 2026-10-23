<?php

namespace Tests\Feature\Students;

use App\Models\AuditLog;
use App\Models\College;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Contracts for the shared, canonical Student Filter.
 *
 * These complement the legacy StudentListFiltersTest: the older suite protects
 * existing list behavior, while this suite protects the shared request names,
 * privacy boundary, same-enrollment semantics, and export/pagination reuse.
 */
class CanonicalStudentFilterTest extends TestCase
{
    use StudentTestHelpers;

    /** @return array{0: College, 1: User} */
    private function collegeWithViewer(string $code, array $permissions = ['students.view']): array
    {
        $college = $this->makeCollege($code);

        return [$college, $this->makeUserWithPermissions($college, $permissions)];
    }

    private function list(College $college, User $user, array $query = []): TestResponse
    {
        return $this->asCollege($college, $user)->get(route('students.index', $query));
    }

    public function test_canonical_controls_are_collapsed_accessible_and_do_not_duplicate_statuses(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('CSF01', [
            'students.view', 'students.view_sensitive_identity',
        ]);

        $html = $this->list($college, $viewer)->assertOk()->getContent();

        $this->assertStringContainsString('data-student-filter', $html);
        $this->assertStringContainsString('<summary', $html);
        $this->assertStringNotContainsString('<details data-student-filter open', $html);

        foreach ([
            'REG.NO', 'REG. DATE', 'ACADEMIC STATUS', 'STATUS', 'PROGRAM/CLASS',
            'SEMESTER/SECTION', 'NAME OF STUDENT', 'UNIQUE STUDENT ID', 'E-MAIL',
            'ABC ID / APAAR ID', 'ENROLLMENT NO.', 'MOBILE NUMBER', 'AADHAAR NUMBER',
            "FATHER'S NAME", "MOTHER'S NAME",
        ] as $label) {
            $this->assertStringContainsString(e($label), $html, "Missing canonical {$label} label.");
        }

        foreach ([
            'student_number', 'admission_date_from', 'admission_date_to', 'enrollment_status',
            'student_status', 'program_id', 'academic_context', 'student_name',
            'unique_student_id', 'email', 'apaar_id', 'enrollment_number', 'mobile_number',
            'aadhaar_last4', 'father_name', 'mother_name',
        ] as $field) {
            $this->assertSame(1, substr_count($html, 'name="'.$field.'"'), "{$field} must have exactly one control.");
        }

        foreach (['academic_term_id', 'section_id', 'batch_id', 'aadhaar_number', 'aadhaar_hash'] as $field) {
            $this->assertStringNotContainsString('name="'.$field.'"', $html);
        }
        // STATUS is student status; ACADEMIC STATUS is enrollment status. The
        // old ambiguous `status` request field is not rendered as a control.
        $this->assertStringNotContainsString('name="status"', $html);
        $this->assertStringContainsString('BATCH', $html);
        $this->assertStringContainsString('No Batch master', $html);
    }

    public function test_aadhaar_filter_is_permission_gated_last_four_only_and_redacted_from_audit(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('CSF02');
        $sensitiveViewer = $this->makeUserWithPermissions($college, [
            'students.view', 'students.export', 'students.view_sensitive_identity',
        ]);
        $target = $this->makeStudent($college, [
            'student_number' => 'STU-AADHAAR-YES',
            'aadhaar_number' => '999941057058',
            'aadhaar_last4' => '7058',
        ]);
        $this->makeStudent($college, [
            'student_number' => 'STU-AADHAAR-NO',
            'aadhaar_last4' => '1111',
        ]);

        $ordinaryHtml = $this->list($college, $viewer)->assertOk()->getContent();
        $this->assertStringNotContainsString('name="aadhaar_last4"', $ordinaryHtml);
        $this->assertStringContainsString('name="apaar_id"', $ordinaryHtml);
        $this->list($college, $viewer, ['aadhaar_last4' => '7058'])->assertForbidden();

        $response = $this->list($college, $sensitiveViewer, ['aadhaar_last4' => '7058'])
            ->assertOk()
            ->assertSee($target->student_number)
            ->assertDontSee('STU-AADHAAR-NO')
            ->assertDontSee('999941057058');
        $html = $response->getContent();
        // The four-digit filter may be retained in the form, but its applied
        // summary is masked and neither the page nor export contains all 12.
        $this->assertStringContainsString('XXXX XXXX 7058', $html);
        $this->assertStringContainsString('aria-label="Toggle Filter Student"', $html);
        $this->assertStringNotContainsString('name="aadhaar_number"', $html);

        $export = $this->asCollege($college, $sensitiveViewer)
            ->get(route('students.export', ['aadhaar_last4' => '7058']))
            ->assertOk();
        $csv = $export->streamedContent();
        $this->assertStringContainsString($target->student_number, $csv);
        $this->assertStringNotContainsString('STU-AADHAAR-NO', $csv);
        $this->assertStringNotContainsString('999941057058', $csv);

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'students.exported')->latest('id')->first();
        $this->assertNotNull($audit);
        $encodedAudit = json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('999941057058', $encodedAudit);
        $this->assertStringNotContainsString('7058', $encodedAudit);
        $this->assertStringContainsString('[last-four filter supplied]', $encodedAudit);
    }

    public function test_canonical_identity_contact_and_relationship_filters_use_existing_columns(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('CSF03');
        $year = $this->makeYear($college, '2026');
        $target = $this->makeStudent($college, [
            'student_number' => 'REG-1001',
            'first_name' => 'Alpha',
            'middle_name' => 'Quantum',
            'last_name' => 'Student',
            'email' => 'alpha.student@example.test',
            'phone' => '9000001001',
            'apaar_id' => 'APAAR-1001',
            'father_name' => 'Rakesh Student',
            'mother_name' => 'Sunita Student',
            'status' => 'active',
            'admission_date' => '2026-07-05',
        ]);
        $this->makeEnrollment($college, $target, $year, null, [
            'enrollment_number' => 'ENR-1001',
            'status' => 'completed',
        ]);
        $other = $this->makeStudent($college, [
            'student_number' => 'REG-2002',
            'first_name' => 'Beta',
            'last_name' => 'Other',
            'email' => 'beta.other@example.test',
            'phone' => '9000002002',
            'apaar_id' => 'APAAR-2002',
            'father_name' => 'Other Father',
            'mother_name' => 'Other Mother',
            'status' => 'inactive',
            'admission_date' => '2026-08-10',
        ]);
        $this->makeEnrollment($college, $other, $year, null, [
            'enrollment_number' => 'ENR-2002',
            'status' => 'active',
        ]);

        $cases = [
            ['student_number' => 'reg-1001'],
            ['unique_student_id' => 'REG-1001'],
            ['student_name' => 'Quantum'],
            ['email' => 'alpha.student'],
            ['apaar_id' => 'APAAR-1001'],
            ['enrollment_number' => 'ENR-1001'],
            ['mobile_number' => '9000001001'],
            ['father_name' => 'Rakesh'],
            ['mother_name' => 'Sunita'],
            ['student_status' => 'active'],
            ['enrollment_status' => 'completed'],
            ['admission_date_from' => '2026-07-01', 'admission_date_to' => '2026-07-31'],
        ];

        foreach ($cases as $query) {
            $response = $this->list($college, $viewer, $query)->assertOk();
            $response->assertViewHas('students', function ($students) use ($target): bool {
                return $students->total() === 1 && $students->first()->is($target);
            });
        }

        $this->list($college, $viewer, ['apaar_id' => 'APAAR'])->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 0);
    }

    public function test_program_and_semester_section_must_match_the_same_enrollment(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('CSF04');
        $yearOne = $this->makeYear($college, '2026');
        $yearTwo = $this->makeYear($college, '2027');
        $programOne = $this->makeProgram($college, 'BSC');
        $programTwo = $this->makeProgram($college, 'BCOM');
        $termOne = $this->makeAcademicTerm($college, $yearOne, 'FIRST', 'First Semester');
        $termTwo = $this->makeAcademicTerm($college, $yearTwo, 'FIRST', 'First Semester');
        $sectionOne = $this->makeSection($college, $yearOne, $programOne, 'A');
        $sectionTwo = $this->makeSection($college, $yearOne, $programOne, 'B');
        $unusedSection = $this->makeSection($college, $yearOne, $programOne, 'C');

        $target = $this->makeStudent($college, ['student_number' => 'STU-SAME-ENROLLMENT']);
        $targetEnrollment = $this->makeEnrollment($college, $target, $yearOne, $programOne, [
            'section_id' => $sectionOne->id,
        ]);
        $this->makeAcademicRecord($college, $target, $yearOne, [
            'enrollment_id' => $targetEnrollment->id,
            'program_id' => $programOne->id,
            'academic_term_id' => $termOne->id,
            'section_id' => $sectionOne->id,
        ]);

        $split = $this->makeStudent($college, ['student_number' => 'STU-SPLIT-ENROLLMENTS']);
        $this->makeEnrollment($college, $split, $yearOne, $programOne, [
            'section_id' => $sectionTwo->id,
        ]);
        $splitSecond = $this->makeEnrollment($college, $split, $yearTwo, $programTwo, [
            'section_id' => $sectionOne->id,
        ]);
        $this->makeAcademicRecord($college, $split, $yearTwo, [
            'enrollment_id' => $splitSecond->id,
            'program_id' => $programTwo->id,
            'academic_term_id' => $termTwo->id,
            'section_id' => $sectionOne->id,
        ]);

        $query = [
            'program_id' => $programOne->id,
            'academic_context' => 'section:'.$sectionOne->id,
        ];
        $this->list($college, $viewer, $query)->assertOk()
            ->assertSee($target->student_number)
            ->assertDontSee($split->student_number)
            ->assertViewHas('students', fn ($students) => $students->total() === 1);

        // A valid but unused context returns none. Malformed standalone values
        // retain the established list contract: they are ignored completely,
        // never partially transformed into a different academic constraint.
        $this->list($college, $viewer, ['academic_context' => 'section:'.$unusedSection->id])
            ->assertOk()->assertViewHas('students', fn ($students) => $students->total() === 0);
        $this->list($college, $viewer, ['academic_context' => 'not-a-context'])
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 2)
            ->assertViewHas('studentFilterApplied', []);
    }

    public function test_invalid_dates_and_cross_tenant_values_are_rejected_not_silently_broadened(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('CSF05');
        [$foreignCollege] = $this->collegeWithViewer('CSF06');
        $foreignProgram = $this->makeProgram($foreignCollege, 'FOREIGN');

        $this->list($college, $viewer, [
            'admission_date_from' => '2026-08-01',
            'admission_date_to' => '2026-07-01',
        ])->assertSessionHasErrors('admission_date_to');

        $this->makeStudent($college, ['student_number' => 'STU-LOCAL-ONLY']);
        $response = $this->list($college, $viewer, ['program_id' => $foreignProgram->id])
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 0);
        $this->assertStringNotContainsString('FOREIGN', $response->getContent());
    }

    public function test_validated_filters_persist_in_pagination_sort_and_export(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('CSF07', ['students.view', 'students.export']);
        foreach (['Alpha', 'Beta', 'Gamma'] as $index => $name) {
            $this->makeStudent($college, [
                'student_number' => 'STU-PAGE-'.$index,
                'first_name' => $name,
                'last_name' => 'Persistent',
                'status' => 'active',
            ]);
        }
        $query = [
            'student_name' => 'Persistent',
            'student_status' => 'active',
            'sort' => 'name',
            'direction' => 'asc',
            'per_page' => 1,
        ];
        $html = $this->list($college, $viewer, $query)->assertOk()->getContent();
        $this->assertStringContainsString('student_name=Persistent', $html);
        $this->assertStringContainsString('student_status=active', $html);
        $this->assertStringContainsString('sort=name', $html);
        $this->assertStringContainsString('direction=asc', $html);

        $csv = $this->asCollege($college, $viewer)->get(route('students.export', $query))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('STU-PAGE-0', $csv);
        $this->assertStringContainsString('STU-PAGE-1', $csv);
        $this->assertStringContainsString('STU-PAGE-2', $csv);
    }
}
