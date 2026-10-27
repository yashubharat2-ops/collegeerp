<?php

namespace Tests\Feature\Students;

use App\Models\StudentDocument;
use App\Models\StudentPromotion;
use Tests\TestCase;

/**
 * The student profile: the mandated six sections on the EXISTING students.show
 * page, plus the lifecycle modules that already own their own screens.
 *
 * Guards three things the brief cares about: the profile is one integrated page
 * (no second "Student Profile" master), every section body is rendered only for
 * its own tab, and the five lifecycle modules stay reachable after the
 * reorganisation — nothing was removed, only regrouped.
 */
class StudentProfileTabsTest extends TestCase
{
    use StudentTestHelpers;

    /** The six sections the brief mandates, in order. */
    private const TABS = [
        'profile' => 'Overview',
        'personal' => 'Personal Information',
        'academic' => 'Academic Information',
        'qualifications' => 'Qualifications',
        'documents' => 'Documents',
        'enrollments' => 'Enrollment History',
    ];

    /** The lifecycle modules that already own a screen, still linked from here. */
    private const RELATED_TABS = [
        'academic-records' => 'Academic Records',
        'id-card' => 'ID Card',
        'promotion' => 'Promotion',
        'transfer' => 'Transfer / TC',
        'history' => 'Lifecycle Log',
    ];

    public function test_the_six_profile_sections_are_rendered_on_the_student_detail_page(): void
    {
        [$college, $student] = $this->makeScenario();
        $viewer = $this->makeUserWithPermissions($college, ['students.view']);

        $response = $this->asCollege($college, $viewer)->get(route('students.show', $student))->assertOk();

        foreach (self::TABS as $key => $label) {
            $response->assertSee(route('students.show', ['student' => $student, 'tab' => $key]), false)
                ->assertSee($label, false);
        }
    }

    public function test_the_lifecycle_modules_remain_reachable_from_the_profile(): void
    {
        [$college, $student] = $this->makeScenario();
        $viewer = $this->makeUserWithPermissions($college, ['students.view']);

        $response = $this->asCollege($college, $viewer)->get(route('students.show', $student))->assertOk();

        foreach (self::RELATED_TABS as $key => $label) {
            $response->assertSee(route('students.show', ['student' => $student, 'tab' => $key]), false)
                ->assertSee($label, false);
        }
    }

    public function test_sidebar_students_group_has_exactly_eight_links_and_no_separate_profile_master(): void
    {
        [$college, $student] = $this->makeScenario();
        $viewer = $this->makeUserWithPermissions($college, ['students.view']);

        $response = $this->asCollege($college, $viewer)->get(route('dashboard'))->assertOk();
        $sidebar = $this->studentNavGroup($response->getContent());

        $this->assertSame(8, substr_count($sidebar, 'class="nav-link"'), 'The STUDENTS nav group must contain exactly 8 entries.');
        $this->assertStringNotContainsString('Student Profile', $sidebar, 'There must be no separate "Student Profile" master menu.');
        $this->assertStringContainsString('student-history', $sidebar);
        $this->assertStringContainsString('student-transfers', $sidebar);

        // No dedicated profile route was introduced either.
        foreach (['student-profiles.index', 'student-profiles.show', 'student-profile.index'] as $route) {
            $this->assertFalse(\Illuminate\Support\Facades\Route::has($route), "Route {$route} must not exist.");
        }
    }

    public function test_each_tab_renders_only_its_own_section(): void
    {
        [$college, $student] = $this->makeScenario();
        $viewer = $this->makeUserWithPermissions($college, [
            'students.view',
            'student_enrollments.view', 'student_enrollments.create',
            'student_academic_records.view', 'student_academic_records.create',
            'student_documents.view', 'student_documents.create',
            'student_id_cards.view', 'student_id_cards.generate',
            'student_promotions.view', 'student_promotions.create',
            'student_transfers.view', 'student_transfers.create',
            'student_history.view',
        ]);

        // Each marker is unique to its tab body: none of them appears in the
        // always-visible header, summary cards or navigation, so asserting the
        // other markers are absent proves only one tab body rendered.
        $markers = [
            'profile' => 'Records at a glance',
            'personal' => 'Parent &amp; guardian',
            'academic' => 'Current academic details',
            'qualifications' => 'Previous qualifications',
            'documents' => '+ Upload document',
            'enrollments' => '+ New enrollment',
            'academic-records' => '+ New record',
            'id-card' => 'Generate / print ID card',
            'promotion' => '+ New promotion',
            'transfer' => '+ New transfer request',
            'history' => 'Lifecycle log',
        ];

        // Sanity check that the tab bodies really do carry this student's rows.
        $this->asCollege($college, $viewer)
            ->get(route('students.show', ['student' => $student, 'tab' => 'enrollments']))
            ->assertOk()->assertSee('ENR-TAB-1', false);
        $this->asCollege($college, $viewer)
            ->get(route('students.show', ['student' => $student, 'tab' => 'documents']))
            ->assertOk()->assertSee('marksheet-tab.pdf', false);
        $this->asCollege($college, $viewer)
            ->get(route('students.show', ['student' => $student, 'tab' => 'transfer']))
            ->assertOk()->assertSee('Model Science College, Indore', false);
        $this->asCollege($college, $viewer)
            ->get(route('students.show', ['student' => $student, 'tab' => 'promotion']))
            ->assertOk()->assertSee('2027-28', false);

        foreach ($markers as $tab => $marker) {
            $response = $this->asCollege($college, $viewer)
                ->get(route('students.show', ['student' => $student, 'tab' => $tab]))
                ->assertOk()
                ->assertSee($marker, false);

            foreach ($markers as $otherTab => $otherMarker) {
                if ($otherTab === $tab || $marker === $otherMarker) {
                    continue;
                }

                $response->assertDontSee($otherMarker, false);
            }
        }
    }

    public function test_unknown_tab_falls_back_to_the_overview_section(): void
    {
        [$college, $student] = $this->makeScenario();
        $viewer = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $viewer)
            ->get(route('students.show', ['student' => $student, 'tab' => 'does-not-exist']))
            ->assertOk()
            ->assertSee('Records at a glance', false)
            ->assertDontSee('Lifecycle log', false);
    }

    public function test_empty_states_are_shown_for_sections_without_data(): void
    {
        $college = $this->makeCollege('TABEMPTY');
        $student = $this->makeStudent($college);
        $viewer = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $viewer)
            ->get(route('students.show', ['student' => $student, 'tab' => 'enrollments']))
            ->assertOk()->assertSee('No enrollments yet.', false);

        $this->asCollege($college, $viewer)
            ->get(route('students.show', ['student' => $student, 'tab' => 'academic']))
            ->assertOk()->assertSee('No active enrollment for the current academic year.', false);

        $this->asCollege($college, $viewer)
            ->get(route('students.show', ['student' => $student, 'tab' => 'qualifications']))
            ->assertOk()->assertSee('No qualifications recorded for this student.', false);

        $this->asCollege($college, $viewer)
            ->get(route('students.show', ['student' => $student, 'tab' => 'academic-records']))
            ->assertOk()->assertSee('No academic records yet.', false);

        $this->asCollege($college, $viewer)
            ->get(route('students.show', ['student' => $student, 'tab' => 'documents']))
            ->assertOk()->assertSee('No documents uploaded yet.', false);

        $this->asCollege($college, $viewer)
            ->get(route('students.show', ['student' => $student, 'tab' => 'promotion']))
            ->assertOk()->assertSee('No promotions recorded.', false);

        $this->asCollege($college, $viewer)
            ->get(route('students.show', ['student' => $student, 'tab' => 'transfer']))
            ->assertOk()->assertSee('No transfer requests.', false);

        $this->asCollege($college, $viewer)
            ->get(route('students.show', ['student' => $student, 'tab' => 'history']))
            ->assertOk()->assertSee('Student record created', false);
    }

    public function test_a_cross_college_student_is_not_found(): void
    {
        $collegeA = $this->makeCollege('TABXA');
        $collegeB = $this->makeCollege('TABXB');
        $studentB = $this->makeStudent($collegeB);
        $adminA = $this->makeUserWithPermissions($collegeA, ['students.view', 'student_history.view']);

        foreach (array_merge(array_keys(self::TABS), array_keys(self::RELATED_TABS)) as $tab) {
            $this->asCollege($collegeA, $adminA)
                ->get(route('students.show', ['student' => $studentB, 'tab' => $tab]))
                ->assertNotFound();
        }
    }

    public function test_students_view_permission_is_required(): void
    {
        [$college, $student] = $this->makeScenario();
        $nobody = $this->makeUserWithPermissions($college, ['student_history.view']);

        $this->asCollege($college, $nobody)->get(route('students.show', $student))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        [$college, $student] = $this->makeScenario();

        $this->get(route('students.show', $student))->assertRedirect(route('login'));
    }

    /**
     * Build a student with one row in every lifecycle module so each tab has data.
     *
     * @return array{0: \App\Models\College, 1: \App\Models\Student, 2: array<string, mixed>}
     */
    private function makeScenario(): array
    {
        $college = $this->makeCollege('TABS');
        $year = $this->makeYear($college, '2026', '2026-27');
        $nextYear = $this->makeNextYear($college, '2027');
        $program = $this->makeProgram($college);
        $section = $this->makeSection($college, $year, $program, 'A');
        $term = $this->makeAcademicTerm($college, $year);
        $documentType = $this->makeDocumentType($college);

        $student = $this->makeStudent($college, ['student_number' => 'STU-TAB-1', 'phone' => '9876543210']);
        $enrollment = $this->makeEnrollment($college, $student, $year, $program, [
            'enrollment_number' => 'ENR-TAB-1',
            'section_id' => $section->id,
            'academic_term_id' => $term->id,
        ]);

        $record = $this->makeAcademicRecord($college, $student, $year, ['program_id' => $program->id]);
        $document = StudentDocument::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'document_type_id' => $documentType->id,
            'title' => 'Class 12 marksheet',
            'file_path' => sprintf('students/%d/%d/tabc123.pdf', $college->id, $student->id),
            'original_filename' => 'marksheet-tab.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 10240,
            'verification_status' => 'pending',
        ]);
        $promotion = StudentPromotion::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'source_enrollment_id' => $enrollment->id,
            'source_academic_year_id' => $year->id,
            'target_academic_year_id' => $nextYear->id,
            'status' => 'pending',
        ]);
        $transfer = $this->makeTransfer($college, $student, [
            'destination_institution' => 'Model Science College, Indore',
        ]);

        return [$college, $student, compact('enrollment', 'record', 'document', 'promotion', 'transfer', 'term', 'section', 'year', 'program')];
    }

    /**
     * Slice the sidebar between the "Students" group heading and the next group
     * heading, so nav assertions cannot be satisfied by another module's links.
     */
    private function studentNavGroup(string $html): string
    {
        $start = strpos($html, '>Students</div>');
        $this->assertNotFalse($start, 'The sidebar must have a Students group heading.');

        $after = $start + strlen('>Students</div>');
        $end = strpos($html, 'nav-group__head', $after);

        return $end === false ? substr($html, $after) : substr($html, $after, $end - $after);
    }
}
