<?php

namespace Tests\Feature\Students;

use App\Domain\Student\Support\Aadhaar;
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
 *
 * The Aadhaar / ABC ID contract changed with the secure identity search: the
 * values are posted once, stored server-side behind an opaque token, and can
 * never be read back out of a URL, the page, the audit log or an export.
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

        // The compact panel renders exactly eleven controls, in a fixed order:
        // single-value filters first, the date range last.
        foreach ([
            'REG.NO', 'NAME OF STUDENT', 'STUDENT STATUS', 'ACADEMIC STATUS',
            'Academic year', 'Department', 'PROGRAM / CLASS', 'SEMESTER / SECTION',
            'AADHAAR NUMBER', 'ABC ID / APAAR ID', 'REG. DATE',
        ] as $label) {
            $this->assertStringContainsString(e($label), $html, "Missing canonical {$label} label.");
        }

        // One control per canonical field: no duplicate names, no second table.
        foreach ([
            'student_number', 'student_name', 'student_status', 'academic_status',
            'academic_year_id', 'department_id', 'program_id', 'academic_context',
            'admission_date_from', 'admission_date_to',
        ] as $field) {
            $this->assertSame(1, substr_count($html, 'name="'.$field.'"'), "{$field} must have exactly one control.");
        }

        // The order the panel is drawn in: every smaller filter precedes the
        // date range, which is always last.
        $positions = [];
        foreach ([
            'name="student_number"', 'name="student_name"', 'name="student_status"',
            'name="academic_status"', 'name="academic_year_id"', 'name="department_id"',
            'name="program_id"', 'name="academic_context"', 'name="aadhaar"',
            'name="apaar_id"', 'name="admission_date_from"',
        ] as $marker) {
            $position = strpos($html, $marker);
            $this->assertNotFalse($position, "{$marker} must be rendered.");
            $positions[$marker] = $position;
        }
        $ordered = $positions;
        asort($ordered);
        $this->assertSame(array_keys($positions), array_keys($ordered), 'The panel must render its filters in canonical order, date range last.');

        // The identity fields exist once each, in the POST sub-form only.
        $this->assertSame(1, substr_count($html, 'name="aadhaar"'));
        $this->assertSame(1, substr_count($html, 'name="apaar_id"'));
        $this->assertStringContainsString(route('students.filter.identity'), $html);
        // …and they are bound to that POST form, not to the GET filter form.
        $this->assertStringContainsString('form="student-identity-filter"', $html);
        $this->assertStringContainsString('id="student-identity-filter"', $html);

        foreach ([
            'academic_term_id', 'section_id', 'batch_id', 'aadhaar_number', 'aadhaar_hash',
            'aadhaar_last4', 'unique_student_id', 'blood_group', 'nationality',
            'date_of_birth_from', 'date_of_birth_to', 'legacy_id',
            // Removed from the compact panel: the parameters still work, the
            // controls must not come back.
            'gender', 'category', 'mother_tongue', 'email', 'mobile_number',
            'enrollment_number', 'father_name', 'mother_name', 'registration_status',
            'enrollment_status',
        ] as $field) {
            $this->assertStringNotContainsString('name="'.$field.'"', $html, "{$field} must not be a control.");
        }

        // STATUS is student status; ACADEMIC STATUS is the derived academic status.
        // The old ambiguous `status` request field is not rendered as a control.
        $this->assertStringNotContainsString('name="status"', $html);
    }

    /**
     * The submit controls live in their own action bar at the bottom of the
     * panel — not in a grid cell — so they can be right-aligned together.
     */
    public function test_apply_and_reset_filters_share_one_action_bar_after_the_grid(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('CSF01A', ['students.view']);

        $html = $this->list($college, $viewer)->assertOk()->getContent();

        $barStart = strpos($html, 'erp-student-filter-actions');
        $this->assertNotFalse($barStart, 'The panel must render one action bar.');

        // The bar comes AFTER the filter grid closes, i.e. it is not a grid cell:
        // no control may appear between the bar and the form's closing tag.
        $actions = substr($html, $barStart, strpos($html, '</form>', $barStart) - $barStart);
        $this->assertStringContainsString('Apply Filters', $actions);
        $this->assertStringContainsString('Reset Filters', $actions);
        $this->assertStringNotContainsString('name="', $actions, 'The action bar must not contain filter controls.');

        // Both sit in the same (right-aligned) button group, primary first.
        $this->assertStringContainsString('erp-student-filter-actions-buttons', $actions);
        $buttons = substr($actions, strpos($actions, 'erp-student-filter-actions-buttons'));
        $applyAt = strpos($buttons, 'Apply Filters');
        $resetAt = strpos($buttons, 'Reset Filters');
        $this->assertNotFalse($applyAt);
        $this->assertNotFalse($resetAt);
        $this->assertLessThan($resetAt, $applyAt, 'Apply Filters stays the primary button, before Reset Filters.');

        // Apply keeps the primary `.button`; Reset keeps the secondary treatment.
        $this->assertMatchesRegularExpression(
            '/<button\b[^>]*type="submit"[^>]*>\s*Apply Filters/',
            $buttons,
            'Apply Filters must stay the primary submit button.'
        );
        $this->assertMatchesRegularExpression(
            '/<a\b(?=[^>]*!bg-slate-200)[^>]*>\s*Reset Filters/',
            $buttons,
            'Reset Filters must stay the secondary button.'
        );

        // The grid still ends before the bar: every filter control is above it.
        foreach (['name="student_number"', 'name="admission_date_to"'] as $field) {
            $position = strpos($html, $field);
            $this->assertNotFalse($position);
            $this->assertLessThan($barStart, $position, "{$field} must be inside the grid, above the action bar.");
        }

        // The placement is a stylesheet contract too: the bar is a flex row
        // justified to the end, and the pair splits the width on small screens.
        $css = (string) file_get_contents(public_path('css/erp-list.css'));
        $this->assertStringContainsString('.erp-student-filter .erp-student-filter-actions {', $css);
        $this->assertMatchesRegularExpression(
            '/\.erp-student-filter \.erp-student-filter-actions \{[^}]*justify-content: flex-end;/s',
            $css,
            'The action bar must be right-aligned.'
        );
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 639px\) \{[^@]*erp-student-filter-actions-buttons \.button/s',
            $css,
            'The action buttons must stay one row on mobile.'
        );
    }

    public function test_identity_search_is_permission_gated_private_and_matched_on_the_server(): void
    {
        [$college, $viewer] = $this->collegeWithViewer('CSF02');
        $sensitiveViewer = $this->makeUserWithPermissions($college, [
            'students.view', 'students.export', 'students.view_sensitive_identity',
        ]);
        $target = $this->makeStudent($college, [
            'student_number' => 'STU-AADHAAR-YES',
            'aadhaar_number' => '999941057058',
            'aadhaar_last4' => '7058',
            'aadhaar_hash' => Aadhaar::hash('999941057058'),
            'apaar_id' => 'APAAR-CSF02-1',
        ]);
        $this->makeStudent($college, [
            'student_number' => 'STU-AADHAAR-NO',
            'aadhaar_last4' => '1111',
            'apaar_id' => 'APAAR-CSF02-2',
        ]);

        // 1. No permission: no control, and the POST is refused.
        $ordinaryHtml = $this->list($college, $viewer)->assertOk()->getContent();
        $this->assertStringNotContainsString('name="aadhaar"', $ordinaryHtml);
        $this->assertStringNotContainsString('name="apaar_id"', $ordinaryHtml);
        $this->asCollege($college, $viewer)
            ->post(route('students.filter.identity'), ['aadhaar' => '7058'])
            ->assertForbidden();

        // 2. A URL can never carry an identity value: the legacy parameters are
        //    ignored instead of being applied.
        $this->list($college, $viewer, ['aadhaar_last4' => '7058', 'apaar_id' => 'APAAR-CSF02-1'])
            ->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 2);

        // 3. Authorized: the value is posted, the redirect carries only a token.
        $response = $this->asCollege($college, $sensitiveViewer)
            ->post(route('students.filter.identity'), ['aadhaar' => '999941057058'])
            ->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('identity=', $location);
        $this->assertStringNotContainsString('999941057058', $location);
        $this->assertStringNotContainsString('7058', $location);

        $html = $this->asCollege($college, $sensitiveViewer)->get($location)
            ->assertOk()
            ->assertSee($target->student_number)
            ->assertDontSee('STU-AADHAAR-NO')
            ->getContent();
        $this->assertStringNotContainsString('999941057058', $html);
        $this->assertStringNotContainsString('7058', $html);
        $this->assertStringContainsString('exact match (hidden)', $html);

        // 4. The export and the audit trail stay clean as well.
        parse_str((string) parse_url($location, PHP_URL_QUERY), $redirectQuery);
        $token = (string) ($redirectQuery['identity'] ?? '');
        $this->assertNotSame('', $token, 'The redirect must carry an opaque identity token.');
        $csv = $this->asCollege($college, $sensitiveViewer)->get(route('students.export', ['identity' => $token]))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString($target->student_number, $csv);
        $this->assertStringNotContainsString('STU-AADHAAR-NO', $csv);
        $this->assertStringNotContainsString('999941057058', $csv);

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'students.exported')->latest('id')->first();
        $this->assertNotNull($audit);
        $encodedAudit = json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('999941057058', $encodedAudit);
        $this->assertStringNotContainsString('7058', $encodedAudit);
        $this->assertStringContainsString('[identity filter supplied]', $encodedAudit);

        // 5. A four-digit Aadhaar is a last-four match; the ABC ID is exact.
        $lastFour = $this->asCollege($college, $sensitiveViewer)
            ->post(route('students.filter.identity'), ['aadhaar' => '7058'])
            ->assertRedirect();
        $this->asCollege($college, $sensitiveViewer)
            ->get((string) $lastFour->headers->get('Location'))
            ->assertOk()->assertSee($target->student_number)->assertDontSee('STU-AADHAAR-NO');

        $apaar = $this->asCollege($college, $sensitiveViewer)
            ->post(route('students.filter.identity'), ['apaar_id' => 'APAAR-CSF02-1'])
            ->assertRedirect();
        $this->asCollege($college, $sensitiveViewer)
            ->get((string) $apaar->headers->get('Location'))
            ->assertOk()->assertSee($target->student_number)->assertDontSee('STU-AADHAAR-NO');
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
            ['student_name' => 'Quantum'],
            ['email' => 'alpha.student'],
            ['enrollment_number' => 'ENR-1001'],
            ['mobile_number' => '9000001001'],
            ['father_name' => 'Rakesh'],
            ['mother_name' => 'Sunita'],
            ['student_status' => 'active'],
            ['enrollment_status' => 'completed'],
            ['academic_status' => 'continue'],
            ['admission_date_from' => '2026-07-01', 'admission_date_to' => '2026-07-05'],
        ];

        foreach ($cases as $query) {
            $response = $this->list($college, $viewer, $query)->assertOk();
            $response->assertViewHas('students', function ($students) use ($target): bool {
                return $students->total() === 1 && $students->first()->is($target);
            });
        }

        // Inactive students are reachable through STUDENT STATUS, and the
        // academic status `pass_out` matches nothing here (nobody graduated).
        $this->list($college, $viewer, ['student_status' => 'inactive'])->assertOk()
            ->assertViewHas('students', fn ($students) => $students->total() === 1 && $students->first()->is($other));
        $this->list($college, $viewer, ['academic_status' => 'pass_out'])->assertOk()
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

        // A section the chosen program/year cannot contain is a validation error,
        // not a silently empty list.
        $this->list($college, $viewer, ['program_id' => $programTwo->id, 'academic_context' => 'section:'.$sectionOne->id])
            ->assertSessionHasErrors('academic_context');
        $this->list($college, $viewer, ['academic_year_id' => $yearTwo->id, 'academic_context' => 'section:'.$sectionOne->id])
            ->assertSessionHasErrors('academic_context');
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

        // Options never cross the tenant boundary either.
        $this->assertStringNotContainsString('(FOREIGN)', $response->getContent());
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
            'academic_status' => 'continue',
            'sort' => 'name',
            'direction' => 'asc',
            'per_page' => 1,
        ];
        $html = $this->list($college, $viewer, $query)->assertOk()->getContent();
        $this->assertStringContainsString('student_name=Persistent', $html);
        $this->assertStringContainsString('student_status=active', $html);
        $this->assertStringContainsString('academic_status=continue', $html);
        $this->assertStringContainsString('sort=name', $html);
        $this->assertStringContainsString('direction=asc', $html);

        $csv = $this->asCollege($college, $viewer)->get(route('students.export', $query))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('STU-PAGE-0', $csv);
        $this->assertStringContainsString('STU-PAGE-1', $csv);
        $this->assertStringContainsString('STU-PAGE-2', $csv);
    }
}
