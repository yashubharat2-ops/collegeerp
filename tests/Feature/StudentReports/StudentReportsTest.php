<?php

namespace Tests\Feature\StudentReports;

use App\Http\Controllers\StudentReportController;
use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentPromotion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Students\StudentTestHelpers;
use Tests\TestCase;

class StudentReportsTest extends TestCase
{
    use StudentTestHelpers;

    private function reporter(College $college): User
    {
        $user = $this->makeUserWithPermissions($college, ['student_reports.view']);
        $this->asCollege($college, $user);

        return $user;
    }

    private function document(College $college, Student $student, string $title, string $status): StudentDocument
    {
        return StudentDocument::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'student_id' => $student->id,
            'title' => $title,
            'file_path' => 'private/'.$college->id.'/'.$student->id.'/'.$title.'.pdf',
            'original_filename' => $title.'.pdf',
            'file_size' => 2048,
            'verification_status' => $status,
        ]);
    }

    public function test_single_read_only_permission_protects_all_views_and_appears_only_for_reporters(): void
    {
        $permission = Permission::where('slug', 'student_reports.view')->firstOrFail();
        $this->assertSame('view', $permission->action);
        $this->assertTrue(Role::where('slug', 'college-admin')->firstOrFail()->permissions()->where('slug', $permission->slug)->exists());
        $this->assertFalse(Permission::whereIn('slug', ['student_reports.create', 'student_reports.update', 'student_reports.delete'])->exists());
        $this->assertFalse(Schema::hasTable('student_reports'));
        $this->seed();
        $this->assertSame(1, Permission::where('slug', $permission->slug)->count());

        $college = $this->makeCollege('SRPERM');
        $student = $this->makeStudent($college);
        $viewer = $this->makeUserWithPermissions($college, ['students.view', 'student_history.view']);

        $this->get(route('student-reports.index'))->assertRedirect(route('login'));
        $this->asCollege($college, $viewer)->get(route('students.index'))->assertOk()
            ->assertDontSee('href="'.route('student-reports.index').'"', false);

        foreach (array_keys(StudentReportController::REPORTS) as $report) {
            $this->asCollege($college, $viewer)
                ->get(route('student-reports.index', ['report' => $report]))->assertForbidden();
        }
        $this->get(route('student-reports.profile', $student))->assertForbidden();
        $this->get(route('student-reports.history', $student))->assertForbidden();

        $this->reporter($college);
        $this->get(route('students.index'))->assertForbidden(); // report permission is independent
        foreach (StudentReportController::REPORTS as $key => $label) {
            $this->get(route('student-reports.index', ['report' => $key]))
                ->assertOk()->assertViewHas('report', $key)->assertSee($label);
        }
        $this->get(route('student-reports.index'))
            ->assertOk()->assertSee('REPORTS')->assertSee('href="'.route('student-reports.index').'"', false);
        $this->get(route('student-reports.profile', $student))->assertOk()->assertSee('No enrollments recorded.');
        $this->get(route('student-reports.history', $student))->assertOk()->assertSee('Student record created');
    }

    public function test_empty_results_and_invalid_filters_are_handled_without_invented_category_data(): void
    {
        $college = $this->makeCollege('SREMPTY');
        $this->reporter($college);
        foreach (['students', 'profile', 'history', 'enrollments', 'admissions', 'strength', 'new_old', 'documents', 'promotions', 'transfers'] as $key) {
            $this->get(route('student-reports.index', ['report' => $key]))->assertOk()->assertSee('No ');
        }
        $this->get(route('student-reports.index', ['report' => 'strength']))
            ->assertViewHas('studentsCount', 0)->assertViewHas('enrollmentsCount', 0);
        $this->get(route('student-reports.index', ['report' => 'demographics']))
            ->assertOk()->assertViewHas('studentsCount', 0)
            ->assertSee('Category and caste are not recorded')->assertSee('No students match these filters.');
        $this->get(route('student-reports.index', ['report' => 'not_a_report']))->assertOk()->assertViewHas('report', 'students');
        $this->get(route('student-reports.index', ['from' => '2026-10-20', 'to' => '2026-09-01']))->assertSessionHasErrors('to');
        $this->get(route('student-reports.index', ['student_status' => 'made-up']))->assertSessionHasErrors('student_status');
    }

    public function test_every_report_and_detail_is_tenant_scoped_even_with_foreign_filter_ids(): void
    {
        $a = $this->makeCollege('SRTA');
        $b = $this->makeCollege('SRTB');
        $yearA = $this->makeYear($a);
        $yearB = $this->makeYear($b);
        $progA = $this->makeProgram($a);
        $progB = $this->makeProgram($b);
        $sectionA = $this->makeSection($a, $yearA, $progA);
        $sectionB = $this->makeSection($b, $yearB, $progB);
        $appA = $this->makeApprovedApplication($a, $yearA, $progA);
        $appB = $this->makeApprovedApplication($b, $yearB, $progB);
        $studentA = $this->makeStudent($a, ['student_number' => 'SR-A-ONLY', 'admission_application_id' => $appA->id, 'gender' => 'male']);
        $studentB = $this->makeStudent($b, ['student_number' => 'SR-B-ONLY', 'admission_application_id' => $appB->id, 'gender' => 'female']);
        $enrollmentA = $this->makeEnrollment($a, $studentA, $yearA, $progA, ['section_id' => $sectionA->id]);
        $enrollmentB = $this->makeEnrollment($b, $studentB, $yearB, $progB, ['section_id' => $sectionB->id]);
        $this->admission($a, $appA, 'ADM-A-ONLY');
        $this->admission($b, $appB, 'ADM-B-ONLY');
        $this->document($a, $studentA, 'DOC-A-ONLY', 'verified');
        $this->document($b, $studentB, 'DOC-B-ONLY', 'rejected');
        StudentPromotion::withoutGlobalScopes()->create(['college_id' => $a->id, 'student_id' => $studentA->id, 'source_enrollment_id' => $enrollmentA->id, 'target_academic_year_id' => $yearA->id]);
        StudentPromotion::withoutGlobalScopes()->create(['college_id' => $b->id, 'student_id' => $studentB->id, 'source_enrollment_id' => $enrollmentB->id, 'target_academic_year_id' => $yearB->id]);
        $this->makeTransfer($a, $studentA, ['enrollment_id' => $enrollmentA->id, 'status' => 'approved', 'tc_status' => 'issued', 'tc_issue_date' => '2026-09-15', 'tc_number' => 'TC-A-ONLY']);
        $this->makeTransfer($b, $studentB, ['enrollment_id' => $enrollmentB->id, 'status' => 'approved', 'tc_status' => 'issued', 'tc_issue_date' => '2026-09-15', 'tc_number' => 'TC-B-ONLY']);

        $viewer = $this->reporter($a);
        foreach (['students', 'profile', 'admissions', 'enrollments', 'new_old', 'documents', 'promotions', 'transfers', 'history'] as $key) {
            $this->get(route('student-reports.index', ['report' => $key]))
                ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1)
                ->assertDontSee('SR-B-ONLY')->assertDontSee('ADM-B-ONLY')->assertDontSee('DOC-B-ONLY')->assertDontSee('TC-B-ONLY');
        }
        $this->get(route('student-reports.index', ['report' => 'strength']))->assertViewHas('studentsCount', 1);
        $this->get(route('student-reports.index', ['report' => 'new_old']))->assertViewHas('counts', ['new' => 1, 'old' => 0]);
        $this->get(route('student-reports.index', ['report' => 'demographics']))
            ->assertViewHas('studentsCount', 1)->assertViewHas('rows', fn ($groups) => $groups->pluck('label')->all() === ['male']);
        $this->get(route('student-reports.profile', $studentA))->assertSee('ADM-A-ONLY')->assertSee('DOC-A-ONLY')->assertDontSee('DOC-B-ONLY');
        $this->get(route('student-reports.history', $studentA))->assertSee('TC-A-ONLY')->assertDontSee('TC-B-ONLY');
        $this->get(route('student-reports.profile', $studentB))->assertNotFound();
        $this->get(route('student-reports.history', $studentB))->assertNotFound();

        $this->get(route('student-reports.index', ['report' => 'students', 'academic_year_id' => $yearB->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        $this->get(route('student-reports.index', ['report' => 'admissions', 'program_id' => $progB->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        $this->get(route('student-reports.index', ['report' => 'enrollments', 'section_id' => $sectionB->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        $this->get(route('student-reports.index', ['report' => 'strength', 'academic_year_id' => $yearB->id]))
            ->assertViewHas('studentsCount', 0);

        // Membership of another college is not a report grant in that college.
        $viewer->colleges()->attach($b->id);
        $this->asCollege($b, $viewer)->get(route('student-reports.index'))->assertForbidden();
    }

    public function test_academic_filters_use_one_matching_enrollment_and_strength_and_new_old_count_correctly(): void
    {
        $college = $this->makeCollege('SRFILTER');
        $year1 = $this->makeYear($college);
        $year2 = $this->makeNextYear($college);
        $science = Department::withoutGlobalScopes()->create(['college_id' => $college->id, 'name' => 'Science', 'code' => 'SCI', 'status' => 'active']);
        $arts = Department::withoutGlobalScopes()->create(['college_id' => $college->id, 'name' => 'Arts', 'code' => 'ART', 'status' => 'active']);
        $prog1 = $this->program($college, $science, 'Biology', 'BIO');
        $prog2 = $this->program($college, $arts, 'History', 'HIS');
        $section1 = $this->makeSection($college, $year1, $prog1, 'A');
        $section2 = $this->makeSection($college, $year2, $prog2, 'B');
        $moved = $this->makeStudent($college, ['student_number' => 'SR-MOVED', 'admission_date' => '2026-08-01']);
        $stayed = $this->makeStudent($college, ['student_number' => 'SR-STAYED', 'admission_date' => '2026-08-02']);
        $old = $this->makeEnrollment($college, $moved, $year1, $prog1, ['enrollment_number' => 'SR-ENR-OLD', 'section_id' => $section1->id, 'status' => 'completed', 'enrollment_date' => '2026-08-10']);
        $new = $this->makeEnrollment($college, $moved, $year2, $prog2, ['enrollment_number' => 'SR-ENR-NEW', 'section_id' => $section2->id, 'enrollment_date' => '2027-08-10']);
        $this->makeEnrollment($college, $stayed, $year1, $prog1, ['section_id' => $section1->id, 'enrollment_date' => '2026-08-11']);

        $this->reporter($college);
        $this->get(route('student-reports.index'))
            ->assertSee('SR-ENR-NEW')->assertDontSee('SR-ENR-OLD'); // current active enrollment
        $context = ['academic_year_id' => $year1->id, 'program_id' => $prog1->id, 'department_id' => $science->id, 'section_id' => $section1->id];
        $this->get(route('student-reports.index', ['report' => 'students', ...$context]))
            ->assertViewHas('rows', function ($rows) use ($moved, $old): bool {
                $row = $rows->getCollection()->firstWhere('id', $moved->id);

                return $rows->total() === 2 && $row?->enrollments->first()?->id === $old->id;
            })->assertSee('SR-ENR-OLD')->assertDontSee('SR-ENR-NEW');
        $this->get(route('student-reports.index', ['report' => 'students', 'academic_year_id' => $year1->id, 'program_id' => $prog2->id, 'section_id' => $section2->id]))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        $this->get(route('student-reports.index', ['report' => 'enrollments', ...$context, 'enrollment_status' => 'completed', 'from' => '2026-08-10', 'to' => '2026-08-10']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $old->id);
        $this->get(route('student-reports.index', ['report' => 'strength', ...$context]))
            ->assertViewHas('studentsCount', 1)->assertViewHas('enrollmentsCount', 1)
            ->assertViewHas('rows', fn ($rows) => (int) $rows->first()->students_count === 1);
        $this->get(route('student-reports.index', ['report' => 'strength', ...$context, 'student_status' => 'all', 'enrollment_status' => 'all']))
            ->assertViewHas('studentsCount', 2)->assertViewHas('enrollmentsCount', 2);
        $this->get(route('student-reports.index', ['report' => 'new_old', 'academic_year_id' => $year2->id, 'entry_type' => 'old']))
            ->assertViewHas('counts', ['new' => 0, 'old' => 1])
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $new->id)
            ->assertSee('Old / Continuing');
        $this->get(route('student-reports.index', ['report' => 'new_old', 'academic_year_id' => $year1->id, 'entry_type' => 'new']))
            ->assertViewHas('counts', ['new' => 2, 'old' => 0])
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
    }

    public function test_student_and_enrollment_status_filters_are_independent(): void
    {
        $college = $this->makeCollege('SRSTATUS');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $active = $this->makeStudent($college, ['student_number' => 'SR-ACTIVE']);
        $withdrawn = $this->makeStudent($college, ['student_number' => 'SR-WITHDRAWN', 'status' => 'withdrawn']);
        $this->makeEnrollment($college, $active, $year, $program, ['status' => 'completed']);
        $this->makeEnrollment($college, $withdrawn, $year, $program, ['status' => 'active']);
        $this->reporter($college);

        $this->get(route('student-reports.index', ['report' => 'students', 'student_status' => 'active']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $active->id);
        $this->get(route('student-reports.index', ['report' => 'students', 'student_status' => 'withdrawn', 'enrollment_status' => 'active']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $withdrawn->id);
        $this->get(route('student-reports.index', ['report' => 'enrollments', 'student_status' => 'active', 'enrollment_status' => 'completed']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->student_id === $active->id);
        $this->get(route('student-reports.index', ['report' => 'strength', 'academic_year_id' => $year->id]))
            ->assertViewHas('studentsCount', 0);
        $this->get(route('student-reports.index', ['report' => 'strength', 'academic_year_id' => $year->id, 'student_status' => 'all', 'enrollment_status' => 'all']))
            ->assertViewHas('studentsCount', 2);
    }

    public function test_strength_and_new_old_do_not_double_count_repeat_enrollments_of_a_student(): void
    {
        $college = $this->makeCollege('SRDISTINCT');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        $section = $this->makeSection($college, $year, $program);
        $student = $this->makeStudent($college);
        // Simulate two historical records in the same year/section. The report
        // reads existing data and must not rely on write-side deduplication.
        $this->makeEnrollment($college, $student, $year, $program, ['section_id' => $section->id, 'enrollment_date' => '2026-08-01']);
        $this->makeEnrollment($college, $student, $year, $program, ['section_id' => $section->id, 'enrollment_date' => '2026-09-01']);
        $this->reporter($college);

        $this->get(route('student-reports.index', ['report' => 'strength', 'academic_year_id' => $year->id]))
            ->assertViewHas('studentsCount', 1)->assertViewHas('enrollmentsCount', 2)
            ->assertViewHas('rows', fn ($rows) => (int) $rows->first()->students_count === 1);
        $this->get(route('student-reports.index', ['report' => 'new_old', 'academic_year_id' => $year->id]))
            ->assertViewHas('counts', ['new' => 1, 'old' => 0])
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
    }

    public function test_official_admissions_link_only_converted_students_and_filter_by_status_and_date(): void
    {
        $college = $this->makeCollege('SRADMIT');
        $year1 = $this->makeYear($college);
        $year2 = $this->makeNextYear($college);
        $program1 = $this->makeProgram($college);
        $program2 = $this->program($college, null, 'History', 'HIS');
        $app1 = $this->makeApprovedApplication($college, $year1, $program1);
        $app2 = $this->makeApprovedApplication($college, $year2, $program2);
        $student = $this->makeStudent($college, ['student_number' => 'SR-CONVERTED', 'admission_application_id' => $app1->id]);
        $this->admission($college, $app1, 'SR-ADM-ONE', '2026-08-04');
        $this->admission($college, $app2, 'SR-ADM-TWO', '2027-08-04', 'cancelled');
        $this->reporter($college);

        $this->get(route('student-reports.index', ['report' => 'admissions', 'academic_year_id' => $year1->id, 'program_id' => $program1->id, 'admission_status' => 'active', 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->application?->student?->id === $student->id)
            ->assertSee('SR-ADM-ONE')->assertSee('SR-CONVERTED')->assertDontSee('SR-ADM-TWO');
        $this->get(route('student-reports.index', ['report' => 'admissions', 'admission_status' => 'cancelled']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1)->assertSee('SR-ADM-TWO')->assertSee('Not converted');
        $this->get(route('student-reports.index', ['report' => 'admissions', 'student_status' => 'active']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1)->assertDontSee('SR-ADM-TWO');
        $this->get(route('student-reports.profile', $student))->assertSee('SR-ADM-ONE')->assertSee($app1->application_number);
    }

    public function test_gender_documents_promotion_transfer_and_derived_history_filters_use_existing_records(): void
    {
        $college = $this->makeCollege('SRLIFE');
        $year = $this->makeYear($college);
        $next = $this->makeNextYear($college);
        $program = $this->makeProgram($college);
        $a = $this->makeStudent($college, ['student_number' => 'SR-LIFE-A', 'gender' => 'female', 'admission_date' => '2026-07-10']);
        $b = $this->makeStudent($college, ['student_number' => 'SR-LIFE-B', 'admission_date' => '2026-07-11']);
        DB::table('students')->where('id', $b->id)->update(['gender' => '  ']); // legacy blank data is not recorded
        $c = $this->makeStudent($college, ['student_number' => 'SR-LIFE-C', 'gender' => 'male', 'admission_date' => '2026-10-10']);
        $enrollment = $this->makeEnrollment($college, $a, $year, $program, ['enrollment_date' => '2026-08-10']);
        $verified = $this->document($college, $a, 'Transcript', 'verified');
        $this->document($college, $a, 'Identification', 'pending');
        DB::table('student_documents')->where('id', $verified->id)->update(['created_at' => '2026-08-01 10:00:00']);
        $promotion = StudentPromotion::withoutGlobalScopes()->create([
            'college_id' => $college->id, 'student_id' => $a->id,
            'source_enrollment_id' => $enrollment->id, 'source_academic_year_id' => $year->id,
            'target_academic_year_id' => $next->id, 'target_program_id' => $program->id,
            'status' => 'approved', 'effective_date' => '2027-06-01',
        ]);
        DB::table('student_promotions')->where('id', $promotion->id)->update(['created_at' => '2026-08-20 10:00:00']);
        $this->makeTransfer($college, $a, [
            'enrollment_id' => $enrollment->id, 'transfer_date' => '2026-09-12',
            'status' => 'approved', 'tc_status' => 'issued', 'tc_issue_date' => '2026-09-15', 'tc_number' => 'SR-TC-ISSUED',
        ]);
        $this->reporter($college);

        $this->get(route('student-reports.index', ['report' => 'demographics', 'from' => '2026-07-01', 'to' => '2026-07-31']))
            ->assertViewHas('studentsCount', 2)
            ->assertViewHas('rows', fn ($groups) => $groups->count() === 2
                && (int) $groups->firstWhere('label', 'female')?->total === 1
                && (int) $groups->firstWhere('label', 'Not recorded')?->total === 1);
        $this->get(route('student-reports.index', ['report' => 'demographics', 'gender' => 'not_recorded']))
            ->assertViewHas('studentsCount', 1);
        $this->get(route('student-reports.index', ['report' => 'documents']))
            ->assertViewHas('rows', function ($rows) use ($a): bool {
                $withDocs = $rows->getCollection()->firstWhere('id', $a->id);

                return $rows->total() === 3 && (int) $withDocs->documents_total === 2
                    && (int) $withDocs->documents_verified === 1 && (int) $withDocs->documents_pending === 1;
            });
        $this->get(route('student-reports.index', ['report' => 'documents', 'document_status' => 'verified', 'from' => '2026-08-01', 'to' => '2026-08-01']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && (int) $rows->first()->documents_total === 1);
        $this->get(route('student-reports.index', ['report' => 'documents', 'document_status' => 'none']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2 && $rows->getCollection()->pluck('id')->contains($b->id) && $rows->getCollection()->pluck('id')->contains($c->id));
        $this->get(route('student-reports.index', ['report' => 'promotions', 'academic_year_id' => $next->id, 'promotion_status' => 'approved', 'from' => '2026-08-20', 'to' => '2026-08-20']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1)->assertSee($next->name);
        $this->get(route('student-reports.index', ['report' => 'transfers', 'academic_year_id' => $year->id, 'tc_status' => 'issued', 'transfer_status' => 'approved', 'from' => '2026-09-12', 'to' => '2026-09-12']))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1)->assertSee('SR-TC-ISSUED');
        $this->get(route('student-reports.profile', $a))
            ->assertSee('Transcript')->assertSee('SR-TC-ISSUED')->assertSee($enrollment->enrollment_number);
        $this->get(route('student-reports.history', $a))->assertSee('Transfer certificate issued')->assertSee('Enrollment created');
        $this->get(route('student-reports.history', ['student' => $a, 'category' => 'transfer', 'from' => '2026-09-15', 'to' => '2026-09-15']))
            ->assertViewHas('events', fn ($events) => $events->total() === 1 && $events->first()->label === 'Transfer certificate issued');
        $this->get(route('student-reports.history', ['student' => $a, 'from' => '2030-01-01']))
            ->assertSee('No history recorded yet.');
    }

    public function test_student_list_paginates_deterministically_and_report_routes_never_write(): void
    {
        $college = $this->makeCollege('SRPAGE');
        $student = null;
        for ($i = 1; $i <= 25; $i++) {
            $student = $this->makeStudent($college, ['student_number' => sprintf('SR-PAGE-%02d', $i), 'admission_date' => '2026-08-01']);
            DB::table('students')->where('id', $student->id)->update(['created_at' => '2026-08-01 09:00:00']);
        }
        $ids = Student::withoutGlobalScopes()->where('college_id', $college->id)->orderByDesc('id')->pluck('id')->all();
        $this->reporter($college);
        $url = ['report' => 'students', 'search' => 'SR-PAGE'];
        $this->get(route('student-reports.index', $url))
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 25 && $rows->getCollection()->pluck('id')->all() === array_slice($ids, 0, 20) && str_contains($rows->nextPageUrl(), 'search=SR-PAGE'));
        $this->get(route('student-reports.index', [...$url, 'page' => 2]))
            ->assertViewHas('rows', fn ($rows) => $rows->getCollection()->pluck('id')->all() === array_slice($ids, 20));

        $before = [DB::table('students')->count(), DB::table('student_enrollments')->count(), AuditLog::count()];
        foreach (array_keys(StudentReportController::REPORTS) as $report) {
            $this->get(route('student-reports.index', ['report' => $report]))->assertOk();
        }
        $this->get(route('student-reports.profile', $student))->assertOk();
        $this->get(route('student-reports.history', $student))->assertOk();
        $this->assertSame($before, [DB::table('students')->count(), DB::table('student_enrollments')->count(), AuditLog::count()]);
        $this->post(route('student-reports.index'))->assertStatus(405);
        $this->put(route('student-reports.profile', $student))->assertStatus(405);
        $this->delete(route('student-reports.history', $student))->assertStatus(405);
        $this->get('/student-reports/create')->assertNotFound();
        $this->assertSame($before, [DB::table('students')->count(), DB::table('student_enrollments')->count(), AuditLog::count()]);
    }

    public function test_student_list_eager_loads_enrollments_for_the_page_in_one_query(): void
    {
        $college = $this->makeCollege('SREAGER');
        $year = $this->makeYear($college);
        $program = $this->makeProgram($college);
        for ($i = 0; $i < 22; $i++) {
            $student = $this->makeStudent($college);
            $this->makeEnrollment($college, $student, $year, $program);
        }
        $this->reporter($college);

        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $this->get(route('student-reports.index'))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 20);
            $enrollmentQueries = collect(DB::getQueryLog())->pluck('query')
                ->filter(fn (string $sql) => (bool) preg_match('/from\\s+["`]?student_enrollments["`]?/i', $sql));
            $this->assertCount(1, $enrollmentQueries, 'Enrollment data must be eager-loaded, not queried per student.');
        } finally {
            DB::disableQueryLog();
        }
    }

    private function program(College $college, ?Department $department, string $name, string $code): Program
    {
        return Program::withoutGlobalScopes()->create([
            'college_id' => $college->id, 'department_id' => $department?->id,
            'name' => $name, 'code' => $code, 'status' => 'active',
        ]);
    }

    private function admission(College $college, $application, string $number, string $date = '2026-09-01', string $status = 'active'): Admission
    {
        return Admission::withoutGlobalScopes()->create([
            'college_id' => $college->id, 'application_id' => $application->id,
            'applicant_id' => $application->applicant_id,
            'academic_year_id' => $application->academic_year_id, 'program_id' => $application->program_id,
            'admission_number' => $number, 'admission_date' => $date, 'status' => $status,
        ]);
    }
}
