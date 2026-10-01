<?php

namespace Tests\Feature\AcademicReports;

use App\Http\Controllers\AcademicReportController;
use App\Models\AcademicAttendance;
use App\Models\AcademicCalendarEvent;
use App\Models\AcademicSubjectEnrollment;
use App\Models\AcademicTerm;
use App\Models\AcademicTimetable;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\FacultySubjectAssignment;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Students\StudentTestHelpers;
use Tests\TestCase;

class AcademicReportsTest extends TestCase
{
    use StudentTestHelpers;

    private const TABLES = [
        'academic_subject_enrollments', 'academic_timetables', 'academic_attendances', 'academic_calendar_events',
        'faculty_subject_assignments', 'sections', 'subjects', 'faculties', 'students', 'student_enrollments',
    ];

    public function test_single_read_only_permission_protects_every_view_and_controls_the_reports_menu(): void
    {
        $permission = Permission::where('slug', 'academic_reports.view')->firstOrFail();
        $this->assertSame('view', $permission->action);
        $this->assertSame('academic_reports', $permission->module);
        $this->assertTrue(Role::where('slug', 'college-admin')->firstOrFail()->permissions()->whereKey($permission->id)->exists());
        $this->assertTrue(Role::where('slug', 'super-admin')->firstOrFail()->permissions()->whereKey($permission->id)->exists());
        $this->assertFalse(Permission::whereIn('slug', ['academic_reports.create', 'academic_reports.update', 'academic_reports.delete', 'academic_reports.export'])->exists());
        $this->assertFalse(Schema::hasTable('academic_reports'));
        $this->seed(); // idempotent
        $this->assertSame(1, Permission::where('slug', $permission->slug)->count());

        $college = $this->makeCollege('ARPERM');
        $this->get(route('academic-reports.index'))->assertRedirect(route('login'));

        // Operational Academic permissions do NOT grant the reports.
        $operator = $this->makeUserWithPermissions($college, [
            'academic_subject_enrollments.view', 'academic_sections.view', 'academic_timetables.view',
            'academic_attendance.view', 'academic_calendar.view', 'academic_workload.view', 'faculty_subject_assignments.view',
        ]);
        $this->asCollege($college, $operator)->get(route('academic-timetables.index'))->assertOk()
            ->assertDontSee('href="'.route('academic-reports.index').'"', false)->assertDontSee('nav-group__label">Reports<', false);
        foreach (array_keys(AcademicReportController::REPORTS) as $report) {
            $this->asCollege($college, $operator)->get(route('academic-reports.index', ['report' => $report]))->assertForbidden();
        }

        // The report permission is independent of the operational pages.
        $reporter = $this->reporter($college);
        $this->get(route('academic-timetables.index'))->assertForbidden();
        foreach (AcademicReportController::REPORTS as $key => $label) {
            $this->get(route('academic-reports.index', ['report' => $key]))
                ->assertOk()->assertViewHas('report', $key)->assertSee($label);
        }
        $this->get(route('academic-reports.index'))->assertOk()
            ->assertSee('nav-group__label">Reports<', false)
            ->assertSee('href="'.route('academic-reports.index').'"', false)
            ->assertDontSee('href="'.route('student-reports.index').'"', false);

        // Student Reports keeps its own permission; each link needs its own grant.
        $studentOnly = $this->makeUserWithPermissions($college, ['student_reports.view']);
        $this->asCollege($college, $studentOnly)->get(route('student-reports.index'))->assertOk()
            ->assertSee('href="'.route('student-reports.index').'"', false)
            ->assertDontSee('href="'.route('academic-reports.index').'"', false);
        $this->get(route('academic-reports.index'))->assertForbidden();

        // A role in one college is not a grant in another college.
        $other = $this->makeCollege('ARPERM2');
        $reporter->colleges()->attach($other->id);
        $this->asCollege($other, $reporter)->get(route('academic-reports.index'))->assertForbidden();
    }

    public function test_reports_menu_stays_after_inventory_with_student_then_academic_reports_only(): void
    {
        $college = $this->makeCollege('ARMENU');
        $user = $this->makeUserWithPermissions($college, ['inventory_dashboard.view', 'student_reports.view', 'academic_reports.view']);
        $html = $this->asCollege($college, $user)->get(route('academic-reports.index'))->assertOk()->getContent();

        $inventory = strpos($html, '>Inventory<');
        $reports = strpos($html, 'nav-group__label">Reports<');
        $student = strpos($html, 'href="'.route('student-reports.index').'"');
        $academic = strpos($html, 'href="'.route('academic-reports.index').'"');
        $platform = strpos($html, 'nav-group__label">Settings<', (int) $reports);
        $platform = $platform === false ? strpos($html, '</nav>', (int) $reports) : $platform;
        $this->assertNotFalse($inventory);
        $this->assertTrue($inventory < $reports && $reports < $student && $student < $academic && $academic < $platform);
        $this->assertSame(1, substr_count($html, 'nav-group__label">Reports<'));

        // Only the two report links live between REPORTS and Administration / Settings.
        $menu = substr($html, $reports, $platform - $reports);
        $this->assertSame(2, substr_count($menu, 'class="nav-link"'));
    }

    public function test_empty_results_and_invalid_filters_are_handled_gracefully(): void
    {
        $college = $this->makeCollege('AREMPTY');
        $this->reporter($college);

        foreach (array_keys(AcademicReportController::REPORTS) as $report) {
            $this->get(route('academic-reports.index', ['report' => $report]))
                ->assertOk()->assertSee('No ')->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        }
        $this->get(route('academic-reports.index', ['report' => 'section_strength']))
            ->assertViewHas('sectionsCount', 0)->assertViewHas('studentsCount', 0)->assertViewHas('capacityTotal', 0);
        $this->get(route('academic-reports.index', ['report' => 'student_attendance']))
            ->assertViewHas('overallPercentage', null)->assertSee('—');
        $this->get(route('academic-reports.index', ['report' => 'workload']))
            ->assertViewHas('hoursTotal', 0.0)->assertViewHas('facultyCount', 0);
        $this->get(route('academic-reports.index', ['report' => 'attendance']))
            ->assertViewHas('counts', ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0]);

        $this->get(route('academic-reports.index', ['report' => 'not_a_report']))->assertOk()->assertViewHas('report', 'subject_enrollments');
        $this->get(route('academic-reports.index', ['report' => 'attendance', 'from' => '2026-10-20', 'to' => '2026-09-01']))->assertSessionHasErrors('to');
        $this->get(route('academic-reports.index', ['report' => 'timetable', 'status' => 'dropped']))->assertSessionHasErrors('status');
        $this->get(route('academic-reports.index', ['report' => 'timetable', 'day_of_week' => 9]))->assertSessionHasErrors('day_of_week');
        $this->get(route('academic-reports.index', ['report' => 'student_attendance', 'below' => 150]))->assertSessionHasErrors('below');
        $this->get(route('academic-reports.index', ['report' => 'attendance', 'attendance_status' => 'made-up']))->assertSessionHasErrors('attendance_status');
        $this->get(route('academic-reports.index', ['academic_year_id' => 'abc']))->assertSessionHasErrors('academic_year_id');
    }

    public function test_every_report_is_tenant_scoped_even_with_foreign_filter_ids(): void
    {
        $a = $this->makeCollege('ARTA');
        $b = $this->makeCollege('ARTB');
        $worldA = $this->world($a, 'ALPHA');
        $worldB = $this->world($b, 'BRAVO');
        $this->reporter($a);

        foreach (array_keys(AcademicReportController::REPORTS) as $report) {
            $this->get(route('academic-reports.index', ['report' => $report]))
                ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1)
                ->assertDontSee('BRAVO');
        }
        $this->get(route('academic-reports.index', ['report' => 'subject_enrollments']))->assertSee('ALPHA-STU')->assertSee('ALPHA Physics');
        $this->get(route('academic-reports.index', ['report' => 'section_strength']))
            ->assertViewHas('studentsCount', 1)
            ->assertViewHas('rows', fn ($rows) => (int) $rows->first()->active_students === 1 && (int) $rows->first()->weekly_periods === 1);
        $this->get(route('academic-reports.index', ['report' => 'workload']))->assertViewHas('periodsCount', 1);
        $this->get(route('academic-reports.index', ['report' => 'calendar']))->assertSee('ALPHA Orientation');

        // Filter dropdowns list only this college's master data.
        $this->get(route('academic-reports.index', ['report' => 'timetable']))
            ->assertViewHas('faculties', fn ($list) => $list->pluck('id')->all() === [$worldA['faculty']->id])
            ->assertViewHas('sections', fn ($list) => $list->pluck('id')->all() === [$worldA['section']->id])
            ->assertViewHas('subjects', fn ($list) => $list->pluck('id')->all() === [$worldA['subject']->id]);

        // Another college's IDs never widen or leak results.
        $foreign = [
            'academic_year_id' => $worldB['year']->id, 'academic_term_id' => $worldB['term']->id,
            'program_id' => $worldB['program']->id, 'department_id' => $worldB['department']->id,
            'section_id' => $worldB['section']->id, 'subject_id' => $worldB['subject']->id, 'faculty_id' => $worldB['faculty']->id,
        ];
        foreach (AcademicReportController::FILTERS as $report => $keys) {
            foreach (array_intersect_key($foreign, array_flip($keys)) as $key => $id) {
                if ($report === 'section_strength' && $key === 'academic_term_id') {
                    continue; // the term narrows the section's counts, asserted below
                }
                $this->get(route('academic-reports.index', ['report' => $report, $key => $id]))
                    ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
            }
        }
        $this->get(route('academic-reports.index', ['report' => 'section_strength', 'academic_term_id' => $worldB['term']->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && (int) $rows->first()->weekly_periods === 0
                && (int) $rows->first()->subject_students === 0 && (int) $rows->first()->subjects_assigned === 0);
    }

    public function test_enrollment_strength_and_subject_wise_reports_use_existing_relationships_and_filters(): void
    {
        $college = $this->makeCollege('ARFILTER');
        $w = $this->world($college, 'ONE');
        $year = $w['year'];
        $arts = Department::withoutGlobalScopes()->create(['college_id' => $college->id, 'name' => 'Arts', 'code' => 'ART', 'status' => 'active']);
        $history = $this->program($college, $arts, 'History', 'HIS');
        $sectionB = $this->makeSection($college, $year, $history, 'B');
        $sectionB->update(['capacity' => null]);
        $term2 = $this->makeAcademicTerm($college, $year, 'SEM2', 'Semester 2', 2);
        $chemistry = $this->subject($college, 'Chemistry', 'CHE');

        // A second student in section A with two subjects (one dropped), and a
        // third in section B whose student enrollment is withdrawn.
        $second = $this->makeStudent($college, ['student_number' => 'ONE-STU-2', 'first_name' => 'Kabir']);
        $secondEnrollment = $this->makeEnrollment($college, $second, $year, $w['program'], ['section_id' => $w['section']->id]);
        $this->subjectEnrollment($college, $secondEnrollment, $term2, $w['subject'], ['status' => 'completed', 'enrollment_date' => '2026-12-01']);
        $this->subjectEnrollment($college, $secondEnrollment, $term2, $chemistry, ['status' => 'dropped', 'enrollment_date' => '2026-12-02']);
        $third = $this->makeStudent($college, ['student_number' => 'ONE-STU-3']);
        $thirdEnrollment = $this->makeEnrollment($college, $third, $year, $history, ['section_id' => $sectionB->id, 'status' => 'withdrawn']);
        $this->subjectEnrollment($college, $thirdEnrollment, $w['term'], $chemistry, ['enrollment_date' => '2026-08-05']);
        // Soft-deleted rows are excluded everywhere.
        $this->subjectEnrollment($college, $thirdEnrollment, $term2, $w['subject'], ['status' => 'completed'])->delete();

        $this->reporter($college);
        $url = fn (array $query) => route('academic-reports.index', ['report' => 'subject_enrollments', ...$query]);

        $this->get($url([]))->assertViewHas('rows', fn ($rows) => $rows->total() === 4)
            ->assertViewHas('studentsCount', 3)->assertViewHas('subjectsCount', 2)
            ->assertSee($w['enrollment']->enrollment_number);
        $this->get($url(['academic_term_id' => $term2->id]))->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->get($url(['subject_id' => $chemistry->id, 'status' => 'dropped']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->student_id === $second->id);
        $this->get($url(['department_id' => $arts->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->section_id === $sectionB->id);
        $this->get($url(['program_id' => $w['program']->id, 'section_id' => $w['section']->id]))->assertViewHas('rows', fn ($rows) => $rows->total() === 3);
        $this->get($url(['search' => 'Kabir']))->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
        $this->get($url(['from' => '2026-12-01', 'to' => '2026-12-01']))->assertViewHas('rows', fn ($rows) => $rows->total() === 1);

        // Section strength: active student enrollments only, distinct counts.
        $strength = route('academic-reports.index', ['report' => 'section_strength', 'academic_year_id' => $year->id]);
        $this->get($strength)
            ->assertViewHas('sectionsCount', 2)->assertViewHas('studentsCount', 2)->assertViewHas('capacityTotal', 40)
            ->assertViewHas('rows', function ($rows) use ($w, $sectionB): bool {
                $a = $rows->getCollection()->firstWhere('id', $w['section']->id);
                $b = $rows->getCollection()->firstWhere('id', $sectionB->id);

                return (int) $a->active_students === 2 && (int) $a->subject_students === 1
                    && (int) $a->subjects_assigned === 1 && (int) $a->faculty_assigned === 1 && (int) $a->weekly_periods === 1
                    && (int) $b->active_students === 0 && (int) $b->subject_students === 1;
            })->assertSee('5%')->assertSee('Not set');
        $this->get(route('academic-reports.index', ['report' => 'section_strength', 'academic_term_id' => $term2->id]))
            ->assertViewHas('rows', fn ($rows) => (int) $rows->getCollection()->firstWhere('id', $w['section']->id)->weekly_periods === 0
                && (int) $rows->getCollection()->firstWhere('id', $w['section']->id)->active_students === 2);
        $this->get(route('academic-reports.index', ['report' => 'section_strength', 'department_id' => $arts->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $sectionB->id);

        // Subject-wise: distinct students per subject / term / section.
        $this->get(route('academic-reports.index', ['report' => 'subject_students']))
            ->assertViewHas('subjectsCount', 2)->assertViewHas('studentsCount', 3)->assertViewHas('enrollmentsCount', 4)
            ->assertViewHas('rows', function ($rows) use ($w, $term2): bool {
                $group = $rows->getCollection()->first(fn ($g) => $g->subject_id === $w['subject']->id && $g->academic_term_id === $term2->id);

                return $rows->total() === 4 && (int) $group->students_count === 1 && (int) $group->completed_count === 1 && (int) $group->active_count === 0;
            });
        $this->get(route('academic-reports.index', ['report' => 'subject_students', 'subject_id' => $chemistry->id]))
            ->assertViewHas('studentsCount', 2)
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2 && (int) $rows->getCollection()->sum('dropped_count') === 1);
    }

    public function test_faculty_timetable_and_workload_reports_follow_assignments_and_active_timetable(): void
    {
        $college = $this->makeCollege('ARFAC');
        $w = $this->world($college, 'FAC');
        $maths = Department::withoutGlobalScopes()->create(['college_id' => $college->id, 'name' => 'Mathematics', 'code' => 'MAT', 'status' => 'active']);
        $other = $this->faculty($college, 'FAC-002', 'Zara', $maths);
        $algebra = $this->subject($college, 'Algebra', 'ALG');
        $sectionB = $this->makeSection($college, $w['year'], $w['program'], 'B');

        // Section-less assignment: its context covers both sections.
        $this->assignment($college, $other, $algebra, $w['year'], $w['term'], ['section_id' => null]);
        $this->assignment($college, $other, $w['subject'], $w['year'], $w['term'], ['status' => 'inactive']);
        $this->timetable($college, $w, ['faculty_id' => $other->id, 'subject_id' => $algebra->id, 'day_of_week' => 2, 'start_time' => '11:00', 'end_time' => '12:00']);
        $this->timetable($college, $w, ['faculty_id' => $other->id, 'subject_id' => $algebra->id, 'section_id' => $sectionB->id, 'day_of_week' => 3, 'start_time' => '09:00', 'end_time' => '09:45']);
        $this->timetable($college, $w, ['faculty_id' => $other->id, 'subject_id' => $algebra->id, 'day_of_week' => 4, 'start_time' => '09:00', 'end_time' => '10:00', 'status' => 'inactive']);
        $this->timetable($college, $w, ['faculty_id' => $other->id, 'subject_id' => $algebra->id, 'day_of_week' => 5, 'start_time' => '09:00', 'end_time' => '10:00'])->delete();
        $this->subjectEnrollment($college, $w['enrollment'], $w['term'], $algebra);

        $this->reporter($college);

        $this->get(route('academic-reports.index', ['report' => 'faculty_subjects']))
            ->assertViewHas('rows', function ($rows) use ($w, $other, $algebra): bool {
                $first = $rows->getCollection()->first();
                $algebraRow = $rows->getCollection()->first(fn ($r) => $r->subject_id === $algebra->id);

                // Ordered by faculty first name: "FAC" (world) before "Zara".
                return $rows->total() === 3 && $first->faculty_id === $w['faculty']->id
                    && (int) $first->weekly_periods === 1 && (int) $first->enrolled_students === 1
                    && $algebraRow->faculty_id === $other->id
                    && (int) $algebraRow->weekly_periods === 2 && (int) $algebraRow->enrolled_students === 1;
            })->assertViewHas('facultyCount', 2)->assertSee('All sections');
        $this->get(route('academic-reports.index', ['report' => 'faculty_subjects', 'department_id' => $maths->id, 'status' => 'active']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->subject_id === $algebra->id);
        $this->get(route('academic-reports.index', ['report' => 'faculty_subjects', 'search' => 'FAC-002']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);

        // Timetable defaults to active entries, in weekly order.
        $this->get(route('academic-reports.index', ['report' => 'timetable']))
            ->assertViewHas('entriesCount', 3)->assertViewHas('weeklyHours', 3.25)
            ->assertViewHas('rows', fn ($rows) => $rows->getCollection()->pluck('day_of_week')->all() === [1, 2, 3])
            ->assertSee('Monday')->assertSee('90 min');
        $this->get(route('academic-reports.index', ['report' => 'timetable', 'status' => 'all']))->assertViewHas('entriesCount', 4);
        $this->get(route('academic-reports.index', ['report' => 'timetable', 'status' => 'inactive']))->assertViewHas('entriesCount', 1);
        $this->get(route('academic-reports.index', ['report' => 'timetable', 'faculty_id' => $other->id, 'day_of_week' => 3]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->section_id === $sectionB->id);
        $this->get(route('academic-reports.index', ['report' => 'timetable', 'section_id' => $sectionB->id]))->assertViewHas('entriesCount', 1);

        // Workload: active entries only; hours summed per faculty/year/term.
        $this->get(route('academic-reports.index', ['report' => 'workload']))
            ->assertViewHas('facultyCount', 2)->assertViewHas('periodsCount', 3)->assertViewHas('hoursTotal', 3.25)
            ->assertViewHas('rows', function ($rows) use ($w, $other): bool {
                $mine = $rows->getCollection()->firstWhere('faculty_id', $w['faculty']->id);
                $theirs = $rows->getCollection()->firstWhere('faculty_id', $other->id);

                return (int) $mine->weekly_periods === 1 && $mine->weekly_hours === 1.5 && $mine->assignments_count === 1
                    && (int) $theirs->weekly_periods === 2 && $theirs->weekly_hours === 1.75
                    && (int) $theirs->sections_count === 2 && (int) $theirs->subjects_count === 1 && $theirs->assignments_count === 1;
            })->assertSee('1.75');
        $this->get(route('academic-reports.index', ['report' => 'workload', 'department_id' => $maths->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->faculty_id === $other->id);
    }

    public function test_attendance_register_summary_and_calendar_filters(): void
    {
        $college = $this->makeCollege('ARATT');
        $w = $this->world($college, 'ATT');
        $chemistry = $this->subject($college, 'Chemistry', 'CHE');
        $chem = $this->subjectEnrollment($college, $w['enrollment'], $w['term'], $chemistry);
        foreach (['2026-09-11' => 'absent', '2026-09-12' => 'late', '2026-09-13' => 'leave'] as $date => $status) {
            $this->attendance($college, $w['subjectEnrollment'], $w['faculty'], $date, $status);
        }
        $this->attendance($college, $chem, $w['faculty'], '2026-09-10', 'absent');
        // Another student, all present, in another program's section.
        $arts = Department::withoutGlobalScopes()->create(['college_id' => $college->id, 'name' => 'Arts', 'code' => 'ART', 'status' => 'active']);
        $history = $this->program($college, $arts, 'History', 'HIS');
        $sectionB = $this->makeSection($college, $w['year'], $history, 'B');
        $peer = $this->makeStudent($college, ['student_number' => 'ATT-PEER']);
        $peerEnrollment = $this->makeEnrollment($college, $peer, $w['year'], $history, ['section_id' => $sectionB->id]);
        $peerSubject = $this->subjectEnrollment($college, $peerEnrollment, $w['term'], $w['subject']);
        $this->attendance($college, $peerSubject, $w['faculty'], '2026-09-10', 'present');

        $this->reporter($college);

        $this->get(route('academic-reports.index', ['report' => 'attendance']))
            ->assertViewHas('counts', ['present' => 2, 'absent' => 2, 'late' => 1, 'leave' => 1])
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 6 && $rows->first()->attendance_date->toDateString() === '2026-09-13');
        $this->get(route('academic-reports.index', ['report' => 'attendance', 'attendance_status' => 'absent', 'subject_id' => $chemistry->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('academic-reports.index', ['report' => 'attendance', 'department_id' => $arts->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->student_id === $peer->id);
        $this->get(route('academic-reports.index', ['report' => 'attendance', 'program_id' => $w['program']->id, 'from' => '2026-09-10', 'to' => '2026-09-11']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 3);

        // Per student + subject: Physics = present, absent, late, leave → 50%.
        $this->get(route('academic-reports.index', ['report' => 'student_attendance', 'search' => $w['student']->student_number]))
            ->assertViewHas('perSubject', true)->assertViewHas('sessionsCount', 5)->assertViewHas('overallPercentage', 40.0)
            ->assertViewHas('rows', function ($rows) use ($w, $chemistry): bool {
                $physics = $rows->getCollection()->firstWhere('subject_id', $w['subject']->id);
                $chem = $rows->getCollection()->firstWhere('subject_id', $chemistry->id);

                return $rows->total() === 2 && (int) $physics->sessions === 4 && (int) $physics->attended_count === 2
                    && (int) $physics->leave_count === 1 && (int) $chem->sessions === 1 && (int) $chem->attended_count === 0;
            })->assertSee('50.00%')->assertSee('0.00%');
        $this->get(route('academic-reports.index', ['report' => 'student_attendance', 'group' => 'student']))
            ->assertViewHas('perSubject', false)->assertViewHas('studentsCount', 2)
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2
                && (int) $rows->getCollection()->firstWhere('student_id', $w['student']->id)->sessions === 5);
        // Shortage list: below 75% is only the first student (40%).
        $this->get(route('academic-reports.index', ['report' => 'student_attendance', 'group' => 'student', 'below' => 75]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->student_id === $w['student']->id);
        $this->get(route('academic-reports.index', ['report' => 'student_attendance', 'below' => 10]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->subject_id === $chemistry->id);

        // Calendar: overlap window, term with year-wide events, type and status.
        $term2 = $this->makeAcademicTerm($college, $w['year'], 'SEM2', 'Semester 2', 2);
        $this->event($college, $w['year'], null, 'Founders Day', '2026-11-20', '2026-11-20', 'holiday');
        $this->event($college, $w['year'], $term2, 'Winter Break', '2026-12-24', '2027-01-02', 'holiday');
        $this->event($college, $w['year'], $term2, 'Draft Seminar', '2027-02-01', '2027-02-01', 'seminar', 'draft');
        $this->event($college, $w['year'], $term2, 'Deleted Event', '2027-02-02', '2027-02-02', 'seminar')->delete();

        $this->get(route('academic-reports.index', ['report' => 'calendar']))
            ->assertViewHas('counts', ['draft' => 1, 'published' => 3, 'cancelled' => 0])
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 4 && $rows->first()->title === 'ATT Orientation')
            ->assertDontSee('Deleted Event');
        $this->get(route('academic-reports.index', ['report' => 'calendar', 'from' => '2027-01-01', 'to' => '2027-01-31']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->title === 'Winter Break');
        $this->get(route('academic-reports.index', ['report' => 'calendar', 'academic_term_id' => $w['term']->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->getCollection()->pluck('title')->all() === ['ATT Orientation', 'Founders Day']);
        $this->get(route('academic-reports.index', ['report' => 'calendar', 'event_type' => 'holiday', 'status' => 'published']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2)
            ->assertViewHas('eventTypes', fn ($types) => $types->all() === ['holiday', 'orientation', 'seminar']);
        $this->get(route('academic-reports.index', ['report' => 'calendar', 'search' => 'seminar']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
    }

    public function test_filters_not_applicable_to_a_report_are_ignored(): void
    {
        $college = $this->makeCollege('ARIGNORE');
        $w = $this->world($college, 'IGN');
        $this->reporter($college);

        // Calendar has no faculty/subject dimension; a stray parameter must
        // not hide its events. Section strength has no subject dimension.
        $this->get(route('academic-reports.index', ['report' => 'calendar', 'faculty_id' => 999999, 'subject_id' => 999999]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1)
            ->assertViewHas('filters', fn ($filters) => $filters['faculty_id'] === null);
        $this->get(route('academic-reports.index', ['report' => 'section_strength', 'subject_id' => 999999]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('academic-reports.index', ['report' => 'timetable', 'faculty_id' => $w['faculty']->id]))
            ->assertViewHas('visible', AcademicReportController::FILTERS['timetable'])
            ->assertSee('name="faculty_id"', false)->assertDontSee('name="event_type"', false);
    }

    public function test_lists_paginate_deterministically_and_keep_filters_in_links(): void
    {
        $college = $this->makeCollege('ARPAGE');
        $w = $this->world($college, 'PAGE');
        for ($i = 1; $i <= 24; $i++) {
            $subject = $this->subject($college, sprintf('Elective %02d', $i), sprintf('EL%02d', $i));
            // Identical dates: order must fall back to the unique ID.
            $this->subjectEnrollment($college, $w['enrollment'], $w['term'], $subject, ['enrollment_date' => '2026-08-01']);
        }
        DB::table('academic_subject_enrollments')->update(['enrollment_date' => '2026-08-01']);
        $ids = AcademicSubjectEnrollment::withoutGlobalScopes()->where('college_id', $college->id)->orderByDesc('id')->pluck('id')->all();
        $this->reporter($college);

        $query = ['report' => 'subject_enrollments', 'academic_term_id' => $w['term']->id];
        $this->get(route('academic-reports.index', $query))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 25 && $rows->perPage() === 20
                && $rows->getCollection()->pluck('id')->all() === array_slice($ids, 0, 20)
                && str_contains($rows->nextPageUrl(), 'academic_term_id='.$w['term']->id)
                && str_contains($rows->nextPageUrl(), 'report=subject_enrollments'))
            ->assertSee('Showing 1–20 of 25');
        $this->get(route('academic-reports.index', [...$query, 'page' => 2]))
            ->assertViewHas('rows', fn ($rows) => $rows->getCollection()->pluck('id')->all() === array_slice($ids, 20));

        // Grouped reports paginate groups, not raw rows.
        $this->get(route('academic-reports.index', ['report' => 'subject_students', 'page' => 2]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 25 && $rows->count() === 5)
            ->assertViewHas('enrollmentsCount', 25);

        // Timetable entries sharing a slot are ordered by ID.
        foreach (range(1, 21) as $n) {
            $this->timetable($college, $w, ['room' => 'R'.$n]);
        }
        $slot = AcademicTimetable::withoutGlobalScopes()->where('college_id', $college->id)->orderBy('id')->pluck('id')->all();
        $this->get(route('academic-reports.index', ['report' => 'timetable', 'page' => 2]))
            ->assertViewHas('rows', fn ($rows) => $rows->getCollection()->pluck('id')->all() === array_slice($slot, 20));
    }

    public function test_report_routes_never_write_and_expose_only_get(): void
    {
        $college = $this->makeCollege('ARREAD');
        $this->world($college, 'READ');
        $this->reporter($college);

        $before = $this->snapshot();
        foreach (array_keys(AcademicReportController::REPORTS) as $report) {
            $this->get(route('academic-reports.index', ['report' => $report]))->assertOk();
        }
        $this->assertSame($before, $this->snapshot());

        $this->post(route('academic-reports.index'), ['report' => 'timetable'])->assertStatus(405);
        $this->put(route('academic-reports.index'))->assertStatus(405);
        $this->patch(route('academic-reports.index'))->assertStatus(405);
        $this->delete(route('academic-reports.index'))->assertStatus(405);
        $this->get('/academic-reports/create')->assertNotFound();
        $this->get('/academic-reports/1/edit')->assertNotFound();
        $this->assertSame($before, $this->snapshot());

        $methods = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'academic-reports'))
            ->flatMap(fn ($route) => $route->methods())->unique()->sort()->values()->all();
        $this->assertSame(['GET', 'HEAD'], $methods);
    }

    public function test_query_count_does_not_grow_with_rows_on_any_report(): void
    {
        $college = $this->makeCollege('ARNPLUS');
        $w = $this->world($college, 'NP');
        $this->reporter($college);

        $counts = fn () => collect(array_keys(AcademicReportController::REPORTS))
            ->mapWithKeys(fn (string $report) => [$report => $this->queriesFor(route('academic-reports.index', ['report' => $report, 'group' => 'subject']))])->all();
        $small = $counts();

        // Grow every dataset well past one page of distinct students, subjects,
        // faculty, sections, timetable slots, attendance rows and events.
        for ($i = 1; $i <= 22; $i++) {
            $faculty = $this->faculty($college, sprintf('NP-F%02d', $i), 'Faculty'.$i, $w['department']);
            $subject = $this->subject($college, 'Subject '.$i, sprintf('NP%02d', $i));
            $section = $this->makeSection($college, $w['year'], $w['program'], sprintf('S%02d', $i));
            $student = $this->makeStudent($college, ['student_number' => sprintf('NP-STU-%02d', $i)]);
            $enrollment = $this->makeEnrollment($college, $student, $w['year'], $w['program'], ['section_id' => $section->id]);
            $subjectEnrollment = $this->subjectEnrollment($college, $enrollment, $w['term'], $subject);
            $this->assignment($college, $faculty, $subject, $w['year'], $w['term'], ['program_id' => $w['program']->id, 'section_id' => $section->id]);
            $this->timetable($college, $w, ['faculty_id' => $faculty->id, 'subject_id' => $subject->id, 'section_id' => $section->id]);
            $this->attendance($college, $subjectEnrollment, $faculty, '2026-09-10', 'present');
            $this->event($college, $w['year'], $w['term'], 'Event '.$i, '2026-10-01', '2026-10-02', 'activity');
        }

        $this->assertSame($small, $counts(), 'Report query counts must not depend on the number of rows.');
    }

    // ----------------------------------------------------------------- helpers

    private function reporter(College $college): User
    {
        $user = $this->makeUserWithPermissions($college, ['academic_reports.view']);
        $this->asCollege($college, $user);

        return $user;
    }

    private function queriesFor(string $url): int
    {
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $this->get($url)->assertOk()->assertViewHas('rows');

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    private function snapshot(): array
    {
        return [
            ...collect(self::TABLES)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all(),
            'max_updated' => DB::table('academic_subject_enrollments')->max('updated_at'),
            'audit_logs' => AuditLog::count(),
        ];
    }

    /** One complete, linked academic context in a college. */
    private function world(College $college, string $tag): array
    {
        $department = Department::withoutGlobalScopes()->create(['college_id' => $college->id, 'name' => $tag.' Science', 'code' => 'SCI', 'status' => 'active']);
        $program = $this->program($college, $department, $tag.' BSc', 'BSC');
        $year = $this->makeYear($college);
        $term = $this->makeAcademicTerm($college, $year);
        $section = $this->makeSection($college, $year, $program, 'A');
        $section->update(['capacity' => 40]);
        $subject = $this->subject($college, $tag.' Physics', 'PHY');
        $faculty = $this->faculty($college, $tag.'-001', $tag, $department);
        $student = $this->makeStudent($college, ['student_number' => $tag.'-STU']);
        $enrollment = $this->makeEnrollment($college, $student, $year, $program, ['section_id' => $section->id, 'enrollment_number' => $tag.'-ENR']);
        $world = compact('department', 'program', 'year', 'term', 'section', 'subject', 'faculty', 'student', 'enrollment');
        $world['subjectEnrollment'] = $this->subjectEnrollment($college, $enrollment, $term, $subject, ['enrollment_date' => '2026-08-01']);
        $this->assignment($college, $faculty, $subject, $year, $term, ['program_id' => $program->id, 'section_id' => $section->id]);
        $this->timetable($college, $world);
        $this->attendance($college, $world['subjectEnrollment'], $faculty, '2026-09-10', 'present');
        $this->event($college, $year, $term, $tag.' Orientation', '2026-09-01', '2026-09-03', 'orientation');

        return $world;
    }

    private function program(College $college, ?Department $department, string $name, string $code): Program
    {
        return Program::withoutGlobalScopes()->create([
            'college_id' => $college->id, 'department_id' => $department?->id, 'name' => $name, 'code' => $code, 'status' => 'active',
        ]);
    }

    private function subject(College $college, string $name, string $code): Subject
    {
        return Subject::withoutGlobalScopes()->create(['college_id' => $college->id, 'name' => $name, 'code' => $code, 'status' => 'active']);
    }

    private function faculty(College $college, string $code, string $firstName, ?Department $department = null): Faculty
    {
        return Faculty::withoutGlobalScopes()->create([
            'college_id' => $college->id, 'employee_code' => $code, 'first_name' => $firstName, 'last_name' => 'Teacher',
            'department_id' => $department?->id, 'status' => 'active',
        ]);
    }

    private function subjectEnrollment(College $college, StudentEnrollment $enrollment, AcademicTerm $term, Subject $subject, array $overrides = []): AcademicSubjectEnrollment
    {
        return AcademicSubjectEnrollment::withoutGlobalScopes()->create(array_merge([
            'college_id' => $college->id, 'student_id' => $enrollment->student_id, 'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $enrollment->academic_year_id, 'academic_term_id' => $term->id,
            'program_id' => $enrollment->program_id, 'section_id' => $enrollment->section_id, 'subject_id' => $subject->id,
            'status' => 'active', 'enrollment_date' => '2026-08-01',
        ], $overrides));
    }

    private function assignment(College $college, Faculty $faculty, Subject $subject, AcademicYear $year, ?AcademicTerm $term, array $overrides = []): FacultySubjectAssignment
    {
        return FacultySubjectAssignment::withoutGlobalScopes()->create(array_merge([
            'college_id' => $college->id, 'faculty_id' => $faculty->id, 'subject_id' => $subject->id,
            'academic_year_id' => $year->id, 'academic_term_id' => $term?->id, 'status' => 'active',
        ], $overrides));
    }

    private function timetable(College $college, array $world, array $overrides = []): AcademicTimetable
    {
        return AcademicTimetable::withoutGlobalScopes()->create(array_merge([
            'college_id' => $college->id, 'academic_year_id' => $world['year']->id, 'academic_term_id' => $world['term']->id,
            'program_id' => $world['program']->id, 'section_id' => $world['section']->id, 'subject_id' => $world['subject']->id,
            'faculty_id' => $world['faculty']->id, 'day_of_week' => 1, 'period' => 1,
            'start_time' => '09:00', 'end_time' => '10:30', 'status' => 'active',
        ], $overrides));
    }

    private function attendance(College $college, AcademicSubjectEnrollment $enrollment, Faculty $faculty, string $date, string $status): AcademicAttendance
    {
        return AcademicAttendance::withoutGlobalScopes()->create([
            'college_id' => $college->id, 'student_id' => $enrollment->student_id, 'student_enrollment_id' => $enrollment->student_enrollment_id,
            'student_subject_enrollment_id' => $enrollment->id, 'academic_year_id' => $enrollment->academic_year_id,
            'academic_term_id' => $enrollment->academic_term_id, 'section_id' => $enrollment->section_id,
            'subject_id' => $enrollment->subject_id, 'faculty_id' => $faculty->id, 'attendance_date' => $date, 'status' => $status,
        ]);
    }

    private function event(College $college, AcademicYear $year, ?AcademicTerm $term, string $title, string $start, string $end, string $type, string $status = 'published'): AcademicCalendarEvent
    {
        return AcademicCalendarEvent::withoutGlobalScopes()->create([
            'college_id' => $college->id, 'academic_year_id' => $year->id, 'academic_term_id' => $term?->id,
            'title' => $title, 'event_type' => $type, 'start_date' => $start, 'end_date' => $end, 'status' => $status,
        ]);
    }
}
