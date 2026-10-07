<?php

namespace Tests\Feature\BulkAction;

use App\Models\AcademicAttendance;
use App\Models\AcademicCalendarEvent;
use App\Models\AcademicSubjectEnrollment;
use App\Models\AcademicTerm;
use App\Models\AcademicTimetable;
use App\Models\AcademicYear;
use App\Models\College;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Support\BulkAction\BulkActionRegistry;
use App\Support\Tenancy\TenantContext;
use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

/**
 * Academic bulk actions — selection, export authorization and tenant isolation.
 *
 * Every Academic listing (subject enrollments, sections, timetables, attendance,
 * calendar, workload) uses the SAME shared foundation as the Student, Admission
 * and Enrollment listings: <x-list.bulk-selection-bar>, <x-list.select-all />,
 * <x-list.row-checkbox /> and the central `bulk-actions.execute` endpoint. These
 * tests pin the three properties that matter for safety:
 *
 *  1. the listings really do expose the shared selection controls and register
 *     the module key the bulk bar posts;
 *  2. authorization is enforced at the action (module permission / policy) and
 *     again at the CSV endpoint, so a hand-edited URL exports nothing extra;
 *  3. ids are re-queried inside the active college — a foreign college's id is
 *     skipped by the handler and matches nothing in the export query.
 *
 * The Academic family is export-only: no test here expects a record to change.
 */
class AcademicBulkActionTest extends TestCase
{
    use DepartmentTestHelpers;

    /**
     * Academic fixtures: year, term, campus-less program, section, subject and a
     * faculty member — plus one timetable entry and one enrolled student.
     *
     * @return array<string, mixed>
     */
    private function academicContext(College $college, string $prefix): array
    {
        $year = AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => "Year {$prefix}",
            'code' => "AY-{$prefix}",
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-05-31',
            'status' => 'active',
        ]);

        $term = AcademicTerm::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'name' => "Term {$prefix}",
            'code' => "T-{$prefix}",
            'type' => 'term',
            'sequence' => 1,
            'status' => 'active',
        ]);

        $program = Program::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => "Program {$prefix}",
            'code' => "P-{$prefix}",
            'status' => 'active',
        ]);

        $section = Section::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'name' => "Section {$prefix}",
            'code' => "S-{$prefix}",
            'status' => 'active',
        ]);

        $subject = Subject::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => "Subject {$prefix}",
            'code' => "SB-{$prefix}",
            'status' => 'active',
        ]);

        $faculty = Faculty::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'employee_code' => "F-{$prefix}",
            'first_name' => 'Ada',
            'last_name' => $prefix,
            'status' => 'active',
        ]);

        $student = Student::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_number' => "STU-{$prefix}",
            'first_name' => 'Stu',
            'last_name' => $prefix,
            'status' => 'active',
        ]);

        $enrollment = StudentEnrollment::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'program_id' => $program->id,
            'section_id' => $section->id,
            'enrollment_number' => "ENR-{$prefix}",
            'enrollment_date' => '2026-06-10',
            'status' => 'active',
        ]);

        $subjectEnrollment = AcademicSubjectEnrollment::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'program_id' => $program->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'status' => 'active',
            'enrollment_date' => '2026-06-11',
        ]);

        $timetable = AcademicTimetable::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'program_id' => $program->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty->id,
            'day_of_week' => 1,
            'period' => 1,
            'start_time' => '09:00',
            'end_time' => '10:30',
            'room' => "R-{$prefix}",
            'status' => 'active',
        ]);

        $attendance = AcademicAttendance::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'student_enrollment_id' => $enrollment->id,
            'student_subject_enrollment_id' => $subjectEnrollment->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty->id,
            'attendance_date' => '2026-07-01',
            'status' => 'present',
        ]);

        $calendarEvent = AcademicCalendarEvent::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'academic_term_id' => $term->id,
            'title' => "Event {$prefix}",
            'event_type' => 'holiday',
            'start_date' => '2026-08-15',
            'end_date' => '2026-08-16',
            'status' => 'published',
        ]);

        return compact(
            'year', 'term', 'program', 'section', 'subject', 'faculty',
            'student', 'enrollment', 'subjectEnrollment', 'timetable', 'attendance', 'calendarEvent',
        );
    }

    /**
     * The `ids` the given export URL carries, as integers.
     *
     * @return array<int, int>
     */
    private function idsInUrl(string $url): array
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return array_map('intval', array_values((array) ($query['ids'] ?? [])));
    }

    private function academicPermissions(): array
    {
        return [
            'academic_subject_enrollments.view',
            'academic_sections.view',
            'academic_timetables.view',
            'academic_attendance.view',
            'academic_calendar.view',
            'academic_workload.view',
        ];
    }

    // ------------------------------------------------------------- registration

    public function test_every_academic_module_registers_its_export_action(): void
    {
        $registry = app(BulkActionRegistry::class);

        $expected = [
            'academic_subject_enrollments' => AcademicSubjectEnrollment::class,
            'academic_sections' => Section::class,
            'academic_timetables' => AcademicTimetable::class,
            'academic_attendance' => AcademicAttendance::class,
            'academic_calendar' => AcademicCalendarEvent::class,
            'academic_workload' => AcademicTimetable::class,
        ];

        foreach ($expected as $module => $model) {
            $this->assertTrue($registry->has($module, 'export'), "Module {$module} must register an export action.");
            $this->assertSame($model, $registry->get($module, 'export')->modelClass());
        }
    }

    // --------------------------------------------------------------- selection

    public function test_academic_listings_render_the_shared_selection_controls(): void
    {
        $college = $this->makeCollege('ACBULK');
        $user = $this->makeUserWithPermissions($college, $this->academicPermissions());
        $ctx = $this->academicContext($college, 'ACBULK');

        app(TenantContext::class)->set($college);

        $pages = [
            ['academic-subject-enrollments.index', 'academic_subject_enrollments', $ctx['subjectEnrollment']->id],
            ['academic-sections.index', 'academic_sections', $ctx['section']->id],
            ['academic-timetables.index', 'academic_timetables', $ctx['timetable']->id],
            ['academic-attendance.index', 'academic_attendance', $ctx['attendance']->id],
            ['academic-calendar.index', 'academic_calendar', $ctx['calendarEvent']->id],
            ['academic-workload.index', 'academic_workload', $ctx['timetable']->id],
        ];

        foreach ($pages as [$route, $module, $rowId]) {
            $response = $this->asCollege($college, $user)->get(route($route));

            $response->assertOk();
            $response->assertSee('data-bulk-selection', false);
            $response->assertSee('data-module="'.$module.'"', false);
            $response->assertSee('data-select-all', false);
            $response->assertSee('data-select-row', false);
            $response->assertSee('data-bulk-action="export"', false);
            // The row checkbox carries the record id the handler will re-query.
            $response->assertSee('value="'.$rowId.'"', false);

            app(TenantContext::class)->set($college);
        }
    }

    // ------------------------------------------------------------ export flow

    public function test_bulk_export_returns_an_authorized_csv_selection(): void
    {
        $college = $this->makeCollege('ACEXP');
        $user = $this->makeUserWithPermissions($college, $this->academicPermissions());
        $ctx = $this->academicContext($college, 'ACEXP');

        $response = $this->asCollege($college, $user)->postJson(route('bulk-actions.execute'), [
            'module' => 'academic_timetables',
            'action' => 'export',
            'ids' => [$ctx['timetable']->id],
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'affected' => 1,
            'skipped_unauthorized' => 0,
        ]);

        $redirect = $response->json('data.redirect');
        $this->assertIsString($redirect);
        $this->assertSame([$ctx['timetable']->id], $this->idsInUrl($redirect));

        $csv = $this->asCollege($college, $user)->get($redirect);

        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));

        $body = $csv->streamedContent();
        $this->assertStringContainsString('Subject ACEXP', $body);
        $this->assertStringContainsString('Ada ACEXP', $body);
        // The export never carries an identity number or a document path.
        $this->assertStringNotContainsString('aadhaar', strtolower($body));
    }

    public function test_bulk_export_requires_the_listing_permission(): void
    {
        $college = $this->makeCollege('ACPERM');
        $ctx = $this->academicContext($college, 'ACPERM');

        // A user with NO academic permission at all.
        $user = $this->makeUserWithPermissions($college, ['academic_calendar.view']);

        $response = $this->asCollege($college, $user)->postJson(route('bulk-actions.execute'), [
            'module' => 'academic_timetables',
            'action' => 'export',
            'ids' => [$ctx['timetable']->id],
        ]);

        $response->assertStatus(403);

        // The CSV endpoint refuses the same user even with a hand-built URL.
        $this->asCollege($college, $user)
            ->get(route('academic-timetables.export', ['ids' => [$ctx['timetable']->id]]))
            ->assertStatus(403);
    }

    public function test_bulk_export_skips_ids_from_another_college(): void
    {
        $collegeA = $this->makeCollege('ACTA');
        $collegeB = $this->makeCollege('ACTB');

        $ctxA = $this->academicContext($collegeA, 'ACTA');
        $ctxB = $this->academicContext($collegeB, 'ACTB');

        $userA = $this->makeUserWithPermissions($collegeA, $this->academicPermissions());

        $response = $this->asCollege($collegeA, $userA)->postJson(route('bulk-actions.execute'), [
            'module' => 'academic_timetables',
            'action' => 'export',
            'ids' => [$ctxA['timetable']->id, $ctxB['timetable']->id],
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'affected' => 1,
            'skipped_unauthorized' => 1,
        ]);

        // The URL the handler built carries ONLY the ids it could authorize.
        $redirect = $response->json('data.redirect');
        $this->assertSame([$ctxA['timetable']->id], $this->idsInUrl($redirect));

        $body = $this->asCollege($collegeA, $userA)->get($redirect)->streamedContent();
        $this->assertStringContainsString('Subject ACTA', $body);
        $this->assertStringNotContainsString('Subject ACTB', $body);

        // A hand-crafted export URL naming the foreign id exports nothing from it.
        $handMade = $this->asCollege($collegeA, $userA)
            ->get(route('academic-timetables.export', ['ids' => [$ctxB['timetable']->id]]));

        $handMade->assertOk();
        $handMadeBody = $handMade->streamedContent();

        $this->assertStringNotContainsString('Subject ACTB', $handMadeBody);
        $this->assertStringNotContainsString('Ada ACTB', $handMadeBody);
    }

    public function test_subject_enrollment_export_is_denied_without_the_module_permission(): void
    {
        $college = $this->makeCollege('ACPOL');
        $ctx = $this->academicContext($college, 'ACPOL');

        // A user who may see the calendar but NOT subject enrollments: the policy
        // denies every record, so nothing is exported and the handler reports it.
        $user = $this->makeUserWithPermissions($college, ['academic_calendar.view']);

        $response = $this->asCollege($college, $user)->postJson(route('bulk-actions.execute'), [
            'module' => 'academic_subject_enrollments',
            'action' => 'export',
            'ids' => [$ctx['subjectEnrollment']->id],
        ]);

        $response->assertStatus(403);
    }

    // ---------------------------------------------------------------- workload

    public function test_workload_export_derives_the_group_from_the_representative_entry(): void
    {
        $college = $this->makeCollege('ACWORK');
        $user = $this->makeUserWithPermissions($college, $this->academicPermissions());
        $ctx = $this->academicContext($college, 'ACWORK');

        // A second period of the SAME group: the workload line is one group with
        // two periods, and its representative id stays the lowest timetable id.
        AcademicTimetable::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'academic_year_id' => $ctx['year']->id,
            'academic_term_id' => $ctx['term']->id,
            'program_id' => $ctx['program']->id,
            'section_id' => $ctx['section']->id,
            'subject_id' => $ctx['subject']->id,
            'faculty_id' => $ctx['faculty']->id,
            'day_of_week' => 3,
            'period' => 2,
            'start_time' => '11:00',
            'end_time' => '12:00',
            'status' => 'active',
        ]);

        $redirect = $this->asCollege($college, $user)->postJson(route('bulk-actions.execute'), [
            'module' => 'academic_workload',
            'action' => 'export',
            'ids' => [$ctx['timetable']->id],
        ])->assertOk()->json('data.redirect');

        $body = $this->asCollege($college, $user)->get($redirect)->streamedContent();

        // One CSV line for the selected group, with the derived totals of BOTH
        // periods (1.50h + 1.00h) — derived server-side, never trusted from the
        // browser.
        $this->assertStringContainsString('Ada ACWORK', $body);
        $this->assertStringContainsString('2.50', $body);

        $lines = array_values(array_filter(explode("\n", trim($body)), fn (string $line): bool => trim($line) !== ''));
        $this->assertCount(2, $lines, 'The workload export must contain the header plus exactly one derived line.');
    }
}
