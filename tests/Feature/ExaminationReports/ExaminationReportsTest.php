<?php

namespace Tests\Feature\ExaminationReports;

use App\Http\Controllers\ExaminationReportController;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Department;
use App\Models\ExamAttendance;
use App\Models\Examination;
use App\Models\ExamResult;
use App\Models\ExamSchedule;
use App\Models\Faculty;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Settings\UserPreferenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Phase4\Phase4TestHelpers;
use Tests\TestCase;

/**
 * Examination Reports — the read-only reporting layer over the existing
 * Examinations module (Phase 5).
 *
 * Covers: the single view permission and its RBAC independence (operational
 * Examination/Results permissions and the pre-existing exam_reports.view stay
 * separate), sidebar placement under REPORTS with exactly 13 report entries,
 * tenant isolation including forged foreign filter ids, filters driven by the
 * existing examination/result relationships, the published-only rule, empty
 * and invalid filters, deterministic pagination, GET-only routes and query
 * counts that never grow with row counts (N+1 protection).
 *
 * Fixtures reuse the Phase 4 helper lineage (ResultTestHelpers →
 * ExamAttendanceTestHelpers) and the real calculation + publishing endpoints —
 * never hand-written grading logic.
 */
class ExaminationReportsTest extends TestCase
{
    use Phase4TestHelpers;

    private const VIEW = ['examination_reports.view'];

    /** Row counts every report shows for the standard two-subject world. */
    private const WORLD_TOTALS = [
        'summary' => 1,
        'schedule' => 2,
        'attendance' => 1,
        'marks' => 4,
        'subject_results' => 2,
        'student_results' => 1,
        'pass_fail' => 1,
        'grades' => 1,
        'merit' => 1,
        'publishing' => 1,
        'marksheet' => 1,
        'grade_card' => 1,
        'result_history' => 1,
    ];

    private const TABLES = [
        'examinations', 'exam_schedules', 'exam_attendances', 'exam_marks', 'exam_results', 'exam_result_items',
    ];

    /* ------------------------------------------------------------------ *
     * Permission / RBAC
     * ------------------------------------------------------------------ */

    public function test_single_read_only_permission_protects_every_view_and_controls_the_reports_menu(): void
    {
        $permission = Permission::where('slug', 'examination_reports.view')->firstOrFail();
        $this->assertSame('view', $permission->action);
        $this->assertSame('examination_reports', $permission->module);
        $this->assertTrue(Role::where('slug', 'college-admin')->firstOrFail()->permissions()->whereKey($permission->id)->exists());
        $this->assertTrue(Role::where('slug', 'super-admin')->firstOrFail()->permissions()->whereKey($permission->id)->exists());
        $this->assertFalse(Permission::whereIn('slug', [
            'examination_reports.create', 'examination_reports.update', 'examination_reports.delete', 'examination_reports.export',
        ])->exists());
        $this->assertFalse(Schema::hasTable('examination_reports'));
        $this->seed(); // idempotent
        $this->assertSame(1, Permission::where('slug', 'examination_reports.view')->count());

        // Kept separate from the pre-existing Phase-4 exam_reports.view.
        $legacy = Permission::where('slug', 'exam_reports.view')->firstOrFail();
        $this->assertNotSame($legacy->id, $permission->id);

        $college = $this->makeCollege('EXRPERM');
        $this->get(route('examination-reports.index'))->assertRedirect(route('login'));

        // Operational Examination / Results permissions do NOT grant the reports.
        $operator = $this->makeUserWithPermissions($college, [
            'examinations.view', 'exam_schedules.view', 'exam_attendance.view', 'exam_marks.view',
            'results.view', 'result_publishing.view', 'marksheets.view', 'grade_cards.view',
            'student_result_history.view', 'exam_reports.view',
        ]);
        $this->asCollege($college, $operator)->get(route('examinations.index'))->assertOk()
            ->assertDontSee('href="'.route('examination-reports.index').'"', false)
            ->assertDontSee('nav-group__label">Reports<', false);
        foreach (array_keys(ExaminationReportController::REPORTS) as $report) {
            $this->asCollege($college, $operator)->get(route('examination-reports.index', ['report' => $report]))->assertForbidden();
        }

        // The report permission is independent of the operational pages.
        $reporter = $this->reporter($college);
        $this->get(route('examinations.index'))->assertForbidden();
        foreach (ExaminationReportController::REPORTS as $key => $label) {
            $this->get(route('examination-reports.index', ['report' => $key]))
                ->assertOk()->assertViewHas('report', $key)->assertSee($label);
        }
        $this->get(route('examination-reports.index'))->assertOk()
            ->assertSee('nav-group__label">Reports<', false)
            ->assertSee('href="'.route('examination-reports.index').'"', false)
            ->assertDontSee('href="'.route('academic-reports.index').'"', false)
            ->assertDontSee('href="'.route('student-reports.index').'"', false);

        // The pre-existing Phase-4 Exam Reports stay behind their own permission.
        $this->get(route('exam-reports.index'))->assertForbidden();
        $legacyOnly = $this->makeUserWithPermissions($college, ['exam_reports.view']);
        $this->asCollege($college, $legacyOnly)->get(route('exam-reports.index'))->assertOk()
            ->assertDontSee('href="'.route('examination-reports.index').'"', false)
            ->assertDontSee('nav-group__label">Reports<', false);
        $this->asCollege($college, $legacyOnly)->get(route('examination-reports.index'))->assertForbidden();

        // Student Reports keeps its own permission too.
        $studentOnly = $this->makeUserWithPermissions($college, ['student_reports.view']);
        $this->asCollege($college, $studentOnly)->get(route('examination-reports.index'))->assertForbidden();

        // A role in one college is not a grant in another college.
        $other = $this->makeCollege('EXRPERM2');
        $reporter->colleges()->attach($other->id);
        $this->asCollege($other, $reporter)->get(route('examination-reports.index'))->assertForbidden();
    }

    /* ------------------------------------------------------------------ *
     * Sidebar placement + exactly 13 entries
     * ------------------------------------------------------------------ */

    public function test_reports_menu_stays_after_inventory_with_student_academic_then_examination_reports(): void
    {
        $college = $this->makeCollege('EXRMENU');
        $user = $this->makeUserWithPermissions($college, [
            'inventory_dashboard.view', 'student_reports.view', 'academic_reports.view', 'examination_reports.view',
        ]);
        $html = $this->asCollege($college, $user)->get(route('examination-reports.index'))->assertOk()->getContent();

        $inventory = strpos($html, '>Inventory<');
        $reports = strpos($html, 'nav-group__label">Reports<');
        $student = strpos($html, 'href="'.route('student-reports.index').'"');
        $academic = strpos($html, 'href="'.route('academic-reports.index').'"');
        $examination = strpos($html, 'href="'.route('examination-reports.index').'"');
        $platform = strpos($html, 'nav-group__label">Settings<', (int) $reports);
        $platform = $platform === false ? strpos($html, '</nav>', (int) $reports) : $platform;
        $this->assertNotFalse($inventory);
        $this->assertTrue($inventory < $reports && $reports < $student && $student < $academic && $academic < $examination && $examination < $platform);
        $this->assertSame(1, substr_count($html, 'nav-group__label">Reports<'));

        // Exactly three report links live between REPORTS and Administration / Settings.
        $menu = substr($html, $reports, $platform - $reports);
        $this->assertSame(3, substr_count($menu, 'class="nav-link"'));

        // The view switcher carries exactly the 13 entries, in the fixed order.
        $navStart = strpos($html, 'aria-label="Examination report views"');
        $this->assertNotFalse($navStart, 'The report view navigation must be present.');
        $nav = substr($html, $navStart, strpos($html, '</nav>', $navStart) - $navStart);
        $this->assertSame(13, substr_count($nav, 'href='));
        $this->assertSame(13, count(ExaminationReportController::REPORTS));
        $previous = -1;
        foreach (ExaminationReportController::REPORTS as $key => $label) {
            $position = strpos($nav, $label);
            $this->assertNotFalse($position, "The nav must contain '{$label}'.");
            $this->assertGreaterThan($previous, $position, "Label '{$label}' must keep its exact position in the order.");
            $this->assertStringContainsString('report='.$key, $nav);
            $previous = $position;
        }

        // The REPORTS heading itself stays plain (no link, no reordering).
        $this->assertStringNotContainsString('<a', substr($html, $reports - 80, 80));
    }

    /* ------------------------------------------------------------------ *
     * Empty states + invalid filters
     * ------------------------------------------------------------------ */

    public function test_empty_results_and_invalid_filters_are_handled_gracefully(): void
    {
        $college = $this->makeCollege('EXREMPTY');
        $this->reporter($college);

        foreach (array_keys(ExaminationReportController::REPORTS) as $report) {
            $this->get(route('examination-reports.index', ['report' => $report]))
                ->assertOk()->assertSee('No ')->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        }

        $this->get(route('examination-reports.index', ['report' => 'not_a_report']))
            ->assertOk()->assertViewHas('report', 'summary');

        $this->get(route('examination-reports.index', ['report' => 'summary', 'from' => '2026-10-20', 'to' => '2026-09-01']))
            ->assertSessionHasErrors('to');
        $this->get(route('examination-reports.index', ['report' => 'summary', 'status' => 'dropped']))
            ->assertSessionHasErrors('status');
        $this->get(route('examination-reports.index', ['report' => 'schedule', 'status' => 'dropped']))
            ->assertSessionHasErrors('status');
        $this->get(route('examination-reports.index', ['report' => 'attendance', 'attendance_status' => 'made-up']))
            ->assertSessionHasErrors('attendance_status');
        $this->get(route('examination-reports.index', ['report' => 'marks', 'mark_status' => 'made-up']))
            ->assertSessionHasErrors('mark_status');
        $this->get(route('examination-reports.index', ['report' => 'student_results', 'result_status' => 'dropped']))
            ->assertSessionHasErrors('result_status');
        $this->get(route('examination-reports.index', ['report' => 'summary', 'academic_year_id' => 'abc']))
            ->assertSessionHasErrors('academic_year_id');
        $this->get(route('examination-reports.index', ['report' => 'schedule', 'subject_id' => 'abc']))
            ->assertSessionHasErrors('subject_id');

        // Filters that do not belong to a report are ignored, not enforced.
        $this->get(route('examination-reports.index', ['report' => 'pass_fail', 'faculty_id' => 999999, 'mark_status' => 'made-up']))
            ->assertOk()
            ->assertViewHas('filters', fn (array $filters) => $filters['faculty_id'] === null && $filters['mark_status'] === null)
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
    }

    /* ------------------------------------------------------------------ *
     * Tenant isolation + forged foreign ids
     * ------------------------------------------------------------------ */

    public function test_every_report_is_tenant_scoped_even_with_foreign_filter_ids(): void
    {
        $a = $this->makeCollege('EXTA');
        $b = $this->makeCollege('EXTB');
        $worldA = $this->world($a, 'ALPH');
        $worldB = $this->world($b, 'BRAV');
        $this->reporter($a);

        foreach (self::WORLD_TOTALS as $report => $total) {
            $this->get(route('examination-reports.index', ['report' => $report]))
                ->assertOk()
                ->assertViewHas('rows', fn ($rows) => $rows->total() === $total)
                ->assertDontSee($worldB['ctx']['exam']->name)
                ->assertDontSee($worldB['student']->student_number);
        }

        // Filter dropdowns list only this college's master data.
        $this->get(route('examination-reports.index', ['report' => 'schedule']))
            ->assertViewHas('examinations', fn ($list) => $list->pluck('id')->all() === [$worldA['ctx']['exam']->id])
            ->assertViewHas('academicYears', fn ($list) => $list->pluck('id')->all() === [$worldA['ctx']['year']->id])
            ->assertViewHas('academicTerms', fn ($list) => $list->pluck('id')->all() === [$worldA['ctx']['term']->id])
            ->assertViewHas('programs', fn ($list) => $list->pluck('id')->all() === [$worldA['ctx']['prog']->id])
            ->assertViewHas('sections', fn ($list) => $list->pluck('id')->all() === [$worldA['ctx']['sec']->id])
            ->assertViewHas('subjects', fn ($list) => $list->pluck('id')->all() === [$worldA['ctx']['sub']->id, $worldA['ctx']['subjects'][1]->id])
            ->assertViewHas('departments', fn ($list) => $list->pluck('id')->all() === [$worldA['department']->id])
            ->assertViewHas('faculties', fn ($list) => $list->pluck('id')->all() === [$worldA['faculty']->id]);
        $this->get(route('examination-reports.index', ['report' => 'student_results']))
            ->assertViewHas('grades', fn ($list) => $list->all() === ['B']);

        // Another college's IDs never widen or leak results on any report.
        $foreign = [
            'academic_year_id' => $worldB['ctx']['year']->id,
            'academic_term_id' => $worldB['ctx']['term']->id,
            'examination_id' => $worldB['ctx']['exam']->id,
            'department_id' => $worldB['department']->id,
            'program_id' => $worldB['ctx']['prog']->id,
            'section_id' => $worldB['ctx']['sec']->id,
            'subject_id' => $worldB['ctx']['sub']->id,
            'faculty_id' => $worldB['faculty']->id,
        ];
        foreach (ExaminationReportController::FILTERS as $report => $keys) {
            foreach (array_intersect_key($foreign, array_flip($keys)) as $key => $id) {
                $this->get(route('examination-reports.index', ['report' => $report, $key => $id]))
                    ->assertOk()
                    ->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
            }
        }
    }

    /* ------------------------------------------------------------------ *
     * Existing examination/result relationships + filters
     * ------------------------------------------------------------------ */

    public function test_filters_read_existing_examination_and_result_relationships(): void
    {
        $college = $this->makeCollege('EXRFILT');
        $w = $this->world($college, 'FILT');
        $this->reporter($college);
        $studentNumber = $w['student']->student_number;

        // Examination summary: examination, status, date-window and search filters.
        $this->get(route('examination-reports.index', ['report' => 'summary', 'examination_id' => $w['ctx']['exam']->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('examination-reports.index', ['report' => 'summary', 'status' => 'draft']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('examination-reports.index', ['report' => 'summary', 'from' => '2026-10-05', 'to' => '2026-10-06']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('examination-reports.index', ['report' => 'summary', 'from' => '2026-11-02', 'to' => '2026-11-03']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('examination-reports.index', ['report' => 'summary', 'search' => 'FILT']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);

        // Schedule: department, faculty, status and exact exam-date window.
        $this->get(route('examination-reports.index', ['report' => 'schedule', 'faculty_id' => $w['faculty']->id, 'department_id' => $w['department']->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 2)
            ->assertViewHas('subjectsCount', 2);
        $this->get(route('examination-reports.index', ['report' => 'schedule', 'status' => 'completed']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('examination-reports.index', ['report' => 'schedule', 'from' => '2026-10-02', 'to' => '2026-10-02']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('examination-reports.index', ['report' => 'schedule', 'subject_id' => $w['ctx']['subjects'][1]->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);

        // Attendance: status vocabulary + student search through the enrollment.
        $this->get(route('examination-reports.index', ['report' => 'attendance', 'attendance_status' => 'present']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1)
            ->assertViewHas('counts', fn ($counts) => $counts['present'] === 1)
            ->assertViewHas('attendanceRate', fn ($rate) => $rate !== null);
        $this->get(route('examination-reports.index', ['report' => 'attendance', 'attendance_status' => 'absent']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('examination-reports.index', ['report' => 'attendance', 'search' => $studentNumber]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('examination-reports.index', ['report' => 'attendance', 'search' => 'ZZZ-NOBODY']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);

        // Marks: entry status + student search.
        $this->get(route('examination-reports.index', ['report' => 'marks', 'mark_status' => 'entered']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 4);
        $this->get(route('examination-reports.index', ['report' => 'marks', 'mark_status' => 'draft']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('examination-reports.index', ['report' => 'marks', 'search' => $studentNumber]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 2);

        // Subject-wise results: grouped items of published results only.
        $this->get(route('examination-reports.index', ['report' => 'subject_results']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 2)
            ->assertViewHas('subjectsCount', 2)
            ->assertViewHas('itemsCount', 2)
            ->assertViewHas('passCount', 2);
        $this->get(route('examination-reports.index', ['report' => 'subject_results', 'subject_id' => $w['ctx']['subjects'][1]->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('examination-reports.index', ['report' => 'subject_results', 'faculty_id' => $w['faculty']->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 2);
        $this->get(route('examination-reports.index', ['report' => 'subject_results', 'result_status' => 'fail']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);

        // Student results: grade, status and student filters.
        $this->get(route('examination-reports.index', ['report' => 'student_results', 'grade' => 'B']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('examination-reports.index', ['report' => 'student_results', 'grade' => 'A']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('examination-reports.index', ['report' => 'student_results', 'result_status' => 'pass']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1)
            ->assertViewHas('passRate', fn ($rate) => $rate === 100.0);
        $this->get(route('examination-reports.index', ['report' => 'student_results', 'search' => $studentNumber]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('examination-reports.index', ['report' => 'student_results', 'search' => $w['otherStudent']->student_number]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0); // unpublished student never appears

        // Pass/fail and grade-wise grouping.
        $this->get(route('examination-reports.index', ['report' => 'pass_fail', 'program_id' => $w['ctx']['prog']->id]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1)
            ->assertViewHas('passCount', 1)
            ->assertViewHas('passRate', fn ($rate) => $rate === 100.0);
        $this->get(route('examination-reports.index', ['report' => 'grades', 'grade' => 'B']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('examination-reports.index', ['report' => 'grades', 'grade' => 'F']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);

        // Merit: ranks are assigned over the filtered set.
        $this->get(route('examination-reports.index', ['report' => 'merit']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1 && $r->first()->rank === 1)
            ->assertViewHas('candidatesCount', 1);

        // Publishing aggregates read both results, but only counts.
        $this->get(route('examination-reports.index', ['report' => 'publishing']))
            ->assertViewHas('resultsCount', 2)
            ->assertViewHas('publishedCount', 1)
            ->assertViewHas('unpublishedCount', 1);
        $this->get(route('examination-reports.index', ['report' => 'publishing', 'status' => 'published']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);

        // Marksheet: grade/date filters and the per-result subject tally.
        $this->get(route('examination-reports.index', ['report' => 'marksheet', 'grade' => 'B']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1 && (int) $r->first()->subjects_count === 2);
        $this->get(route('examination-reports.index', ['report' => 'marksheet', 'from' => '2026-01-01']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('examination-reports.index', ['report' => 'marksheet', 'from' => '2030-01-01']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('examination-reports.index', ['report' => 'marksheet', 'search' => $studentNumber]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);

        // Grade card: the stored grade point comes from the result's scale.
        $this->get(route('examination-reports.index', ['report' => 'grade_card', 'grade' => 'B']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1
                && $r->first()->relationLoaded('gradeScale')
                && (float) $r->first()->gradeScale->items->firstWhere('grade', 'B')->grade_point === 7.0);

        // Student result history: grouped per student with latest examination.
        $this->get(route('examination-reports.index', ['report' => 'result_history']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1
                && (int) $r->first()->results_count === 1
                && (int) $r->first()->latest->examination_id === (int) $w['ctx']['exam']->id)
            ->assertViewHas('resultsCount', 1)
            ->assertViewHas('passCount', 1);
        $this->get(route('examination-reports.index', ['report' => 'result_history', 'search' => $w['otherStudent']->student_number]))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('examination-reports.index', ['report' => 'result_history', 'result_status' => 'pass']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
    }

    /* ------------------------------------------------------------------ *
     * Published-only rule + soft deletes
     * ------------------------------------------------------------------ */

    public function test_published_only_rule_and_soft_deleted_rows_are_respected(): void
    {
        $college = $this->makeCollege('EXRPUB');
        $w = $this->world($college, 'PUB');
        $this->reporter($college);

        foreach (['student_results', 'subject_results', 'pass_fail', 'grades', 'merit', 'marksheet', 'grade_card', 'result_history'] as $report) {
            $this->get(route('examination-reports.index', ['report' => $report]))
                ->assertOk()
                ->assertDontSee($w['otherStudent']->student_number);
        }
        $this->get(route('examination-reports.index', ['report' => 'student_results']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1)
            ->assertSee($w['student']->student_number);

        // The publishing report reads aggregate counts only — never student rows.
        $this->get(route('examination-reports.index', ['report' => 'publishing']))
            ->assertOk()
            ->assertViewHas('resultsCount', 2)
            ->assertViewHas('publishedCount', 1)
            ->assertViewHas('unpublishedCount', 1)
            ->assertDontSee($w['otherStudent']->student_number);

        // Soft-deleted results disappear from every result-backed report.
        $w['result']->delete();
        $this->get(route('examination-reports.index', ['report' => 'student_results']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('examination-reports.index', ['report' => 'marksheet']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
        $this->get(route('examination-reports.index', ['report' => 'publishing']))
            ->assertViewHas('publishedCount', 0)
            ->assertViewHas('resultsCount', 1);

        // Soft-deleted schedules disappear from schedule-backed reports.
        $w['ctx']['schedule']->delete();
        $this->get(route('examination-reports.index', ['report' => 'schedule']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 1);
        $this->get(route('examination-reports.index', ['report' => 'attendance']))
            ->assertViewHas('rows', fn ($r) => $r->total() === 0);
    }

    /* ------------------------------------------------------------------ *
     * Deterministic pagination
     * ------------------------------------------------------------------ */

    public function test_lists_paginate_deterministically_and_keep_filters(): void
    {
        $college = $this->makeCollege('EXRPAGE');
        $ctx = $this->makeExamContextWithSubjects($college, 'PAGE', 1);
        $scale = $this->makeGradeScale($college);
        $this->reporter($college);

        // 21 identical published results: identical sort keys force the id tiebreak.
        $ids = [];
        for ($i = 1; $i <= 21; $i++) {
            [, $enrollment] = $this->makeEnrolledStudent($college, $ctx, sprintf('PG%02d', $i));
            $ids[] = ExamResult::create([
                'college_id' => $college->id,
                'examination_id' => $ctx['exam']->id,
                'student_enrollment_id' => $enrollment->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'grade_scale_id' => $scale->id,
                'total_max_marks' => 100,
                'total_obtained_marks' => 75,
                'percentage' => 75,
                'overall_grade' => 'B',
                'result_status' => ExamResult::RESULT_PASS,
                'calculation_status' => ExamResult::CALCULATION_CALCULATED,
                'calculated_at' => now(),
                // Distinct timestamps make the published_at DESC order explicit.
                'published_at' => now()->startOfDay()->addSeconds($i),
            ])->id;
        }

        $query = ['report' => 'merit', 'academic_year_id' => $ctx['year']->id, 'result_status' => 'pass'];
        $this->get(route('examination-reports.index', $query))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 21 && $rows->perPage() === 20
                && $rows->getCollection()->pluck('id')->all() === array_slice($ids, 0, 20)
                && $rows->getCollection()->first()->rank === 1
                && str_contains($rows->nextPageUrl(), 'academic_year_id='.$ctx['year']->id)
                && str_contains($rows->nextPageUrl(), 'report=merit')
                && str_contains($rows->nextPageUrl(), 'result_status=pass'))
            ->assertSee('Showing 1–20 of 21');
        $this->get(route('examination-reports.index', [...$query, 'page' => 2]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 21
                && $rows->getCollection()->pluck('id')->all() === array_slice($ids, 20)
                && $rows->getCollection()->first()->rank === 21);

        // Student result report orders by published_at DESC, then id DESC.
        $this->get(route('examination-reports.index', ['report' => 'student_results', 'page' => 2]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 21
                && $rows->getCollection()->pluck('id')->all() === [$ids[0]]);

        // Summary paginates examinations with a deterministic id tiebreak too.
        for ($i = 1; $i <= 20; $i++) {
            Examination::create([
                'college_id' => $college->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'name' => sprintf('Exam PAGE %02d', $i),
                'code' => sprintf('EX-PG-%02d', $i),
                'exam_type' => 'Internal',
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-10',
                'status' => 'draft',
            ]);
        }
        $examIds = Examination::withoutGlobalScopes()->where('college_id', $college->id)
            ->orderByDesc('id')->pluck('id')->all();
        $this->assertSame(21, count($examIds));
        $this->get(route('examination-reports.index', ['report' => 'summary']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 21
                && $rows->getCollection()->pluck('id')->all() === array_slice($examIds, 0, 20))
            ->assertSee('Showing 1–20 of 21');
        $this->get(route('examination-reports.index', ['report' => 'summary', 'page' => 2]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 21 && $rows->count() === 1
                && $rows->first()->id === $examIds[20]);
    }

    /* ------------------------------------------------------------------ *
     * GET-only + read-only
     * ------------------------------------------------------------------ */

    public function test_report_routes_never_write_and_expose_only_get(): void
    {
        $college = $this->makeCollege('EXRREAD');
        $this->world($college, 'READ');
        $this->reporter($college);

        $before = $this->snapshot();
        foreach (array_keys(ExaminationReportController::REPORTS) as $report) {
            $this->get(route('examination-reports.index', ['report' => $report]))->assertOk();
        }
        $this->assertSame($before, $this->snapshot());

        $this->post(route('examination-reports.index'), ['report' => 'merit'])->assertStatus(405);
        $this->put(route('examination-reports.index'))->assertStatus(405);
        $this->patch(route('examination-reports.index'))->assertStatus(405);
        $this->delete(route('examination-reports.index'))->assertStatus(405);
        $this->get('/examination-reports/create')->assertNotFound();
        $this->get('/examination-reports/1/edit')->assertNotFound();
        $this->assertSame($before, $this->snapshot());

        $methods = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'examination-reports'))
            ->flatMap(fn ($route) => $route->methods())->unique()->sort()->values()->all();
        $this->assertSame(['GET', 'HEAD'], $methods);
    }

    /* ------------------------------------------------------------------ *
     * Query-count / N+1 protection
     * ------------------------------------------------------------------ */

    public function test_query_count_does_not_grow_with_rows_on_any_report(): void
    {
        $college = $this->makeCollege('EXRNPLUS');
        $ctx = $this->makeExamContextWithSubjects($college, 'NPL', 2);
        $scale = $this->makeGradeScale($college);
        [$student, $enrollment] = $this->makeEnrolledStudent($college, $ctx, 'NPL');

        $this->recordMark($ctx['schedules'][0], $enrollment, 80.0);
        $this->recordMark($ctx['schedules'][1], $enrollment, 70.0);

        $calculator = $this->makeUserWithPermissions($college, [
            'results.view', 'result_calculation.view', 'result_calculation.calculate', 'result_publishing.view', 'result_publishing.publish',
        ]);
        $this->asCollege($college, $calculator)
            ->post(route('result-calculation.calculate'), [
                'examination_id' => $ctx['exam']->id,
                'grade_scale_id' => $scale->id,
            ])->assertRedirect();
        $result = $this->withTenant($college, fn () => $this->resultFor($ctx['exam'], $enrollment));
        $this->asCollege($college, $calculator)
            ->post(route('result-publishing.publish', $result))
            ->assertRedirect();

        ExamAttendance::create([
            'college_id' => $college->id,
            'exam_schedule_id' => $ctx['schedule']->id,
            'student_enrollment_id' => $enrollment->id,
            'attendance_status' => ExamAttendance::STATUS_PRESENT,
            'marked_at' => now(),
        ]);

        $this->reporter($college);

        $counts = fn () => collect(array_keys(ExaminationReportController::REPORTS))
            ->mapWithKeys(fn (string $report) => [$report => $this->queriesFor(route('examination-reports.index', ['report' => $report]))])->all();
        $small = $counts();

        // Grow every dataset well past one page of exams, schedules, marks,
        // attendance rows and published results — query counts must not move.
        for ($i = 1; $i <= 21; $i++) {
            $exam = Examination::create([
                'college_id' => $college->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'name' => 'NPL Exam '.$i,
                'code' => 'NPL-E'.$i,
                'exam_type' => 'Internal',
                'start_date' => '2026-11-01',
                'end_date' => '2026-11-10',
                'status' => 'published',
            ]);
            $schedule = ExamSchedule::create([
                'college_id' => $college->id,
                'examination_id' => $exam->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'program_id' => $ctx['prog']->id,
                'section_id' => $ctx['sec']->id,
                'subject_id' => $ctx['sub']->id,
                'exam_date' => '2026-11-02',
                'start_time' => '09:00:00',
                'end_time' => '12:00:00',
                'max_marks' => 100,
                'passing_marks' => 40,
                'status' => ExamSchedule::STATUS_COMPLETED,
            ]);
            ExamAttendance::create([
                'college_id' => $college->id,
                'exam_schedule_id' => $schedule->id,
                'student_enrollment_id' => $enrollment->id,
                'attendance_status' => ExamAttendance::STATUS_PRESENT,
                'marked_at' => now(),
            ]);
            $this->recordMark($schedule, $enrollment, 60.0 + ($i % 30));
            ExamResult::create([
                'college_id' => $college->id,
                'examination_id' => $exam->id,
                'student_enrollment_id' => $enrollment->id,
                'academic_year_id' => $ctx['year']->id,
                'academic_term_id' => $ctx['term']->id,
                'grade_scale_id' => $scale->id,
                'total_max_marks' => 100,
                'total_obtained_marks' => 75,
                'percentage' => 75,
                'overall_grade' => 'B',
                'result_status' => ExamResult::RESULT_PASS,
                'calculation_status' => ExamResult::CALCULATION_CALCULATED,
                'calculated_at' => now(),
                'published_at' => now(),
            ]);
        }

        $this->assertSame($small, $counts(), 'Report query counts must not depend on the number of rows.');
    }

    /* ----------------------------------------------------------------- helpers */

    private function reporter(College $college): User
    {
        $user = $this->makeUserWithPermissions($college, self::VIEW);
        $this->asCollege($college, $user);

        return $user;
    }

    private function queriesFor(string $url): int
    {
        $this->primeChromeCache();

        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $this->get($url)->assertOk()->assertViewHas('rows');

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    /**
     * Warm the one cached read the page chrome performs.
     *
     * layouts/sidebar.blade.php asks UserPreferenceService for the signed-in account's
     * interface preferences, and that service wraps its lookup in Cache::remember: the
     * FIRST render for a given user in a test costs one extra SELECT and every later one
     * costs none. That query belongs to the chrome, not to a report, and it lands on
     * whichever measurement happens to come first in a file. Warming it here leaves the
     * row count as the only variable between the two measurements of a report, and the
     * comparison stays the strict equality it was written as.
     */
    private function primeChromeCache(): void
    {
        $user = auth()->user();

        if ($user instanceof User) {
            app(UserPreferenceService::class)->resolved($user);
        }
    }

    private function snapshot(): array
    {
        return [
            ...collect(self::TABLES)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all(),
            'results_updated' => DB::table('exam_results')->max('updated_at'),
            'audit_logs' => AuditLog::count(),
        ];
    }

    /**
     * One college with a complete Examination world: a two-subject exam, one
     * calculated + published student, one calculated-only student, marks, an
     * attendance row, plus department and faculty wired into the context.
     *
     * @return array<string, mixed>
     */
    private function world(College $college, string $prefix): array
    {
        $ctx = $this->makeExamContextWithSubjects($college, $prefix, 2);
        $scale = $this->makeGradeScale($college);

        [$student, $enrollment] = $this->makeEnrolledStudent($college, $ctx, $prefix);
        [$otherStudent, $otherEnrollment] = $this->makeEnrolledStudent($college, $ctx, $prefix.'2');

        $this->recordMark($ctx['schedules'][0], $enrollment, 80.0);
        $this->recordMark($ctx['schedules'][1], $enrollment, 70.0);
        $this->recordMark($ctx['schedules'][0], $otherEnrollment, 50.0);
        $this->recordMark($ctx['schedules'][1], $otherEnrollment, 45.0);

        $calculator = $this->makeUserWithPermissions($college, [
            'results.view', 'result_calculation.view', 'result_calculation.calculate',
            'result_publishing.view', 'result_publishing.publish',
        ]);
        $this->asCollege($college, $calculator)
            ->post(route('result-calculation.calculate'), [
                'examination_id' => $ctx['exam']->id,
                'grade_scale_id' => $scale->id,
            ])->assertRedirect();

        $result = $this->withTenant($college, fn () => $this->resultFor($ctx['exam'], $enrollment));

        $this->asCollege($college, $calculator)
            ->post(route('result-publishing.publish', $result))
            ->assertRedirect();
        $result = $this->withTenant($college, fn () => $result->fresh());

        // Department + faculty relationships behind the dedicated filters.
        $department = Department::create(['college_id' => $college->id, 'name' => 'Dept '.$prefix, 'code' => 'D-'.$prefix, 'status' => 'active']);
        $ctx['prog']->update(['department_id' => $department->id]);
        foreach ($ctx['subjects'] as $subject) {
            $subject->update(['department_id' => $department->id]);
        }
        $faculty = Faculty::create([
            'college_id' => $college->id,
            'employee_code' => 'F-'.$prefix,
            'first_name' => 'Prof',
            'last_name' => $prefix,
            'status' => 'active',
        ]);
        foreach ($ctx['schedules'] as $schedule) {
            $schedule->update(['faculty_id' => $faculty->id]);
        }

        $attendance = ExamAttendance::create([
            'college_id' => $college->id,
            'exam_schedule_id' => $ctx['schedule']->id,
            'student_enrollment_id' => $enrollment->id,
            'attendance_status' => ExamAttendance::STATUS_PRESENT,
            'marked_at' => now(),
        ]);

        return compact(
            'college', 'ctx', 'scale', 'student', 'enrollment', 'otherStudent', 'otherEnrollment',
            'result', 'department', 'faculty', 'attendance',
        );
    }
}
