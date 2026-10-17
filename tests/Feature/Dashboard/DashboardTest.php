<?php

namespace Tests\Feature\Dashboard;

use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\Faculty;
use App\Models\Role;
use App\Models\User;
use Tests\Feature\Students\StudentTestHelpers;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use StudentTestHelpers;

    public function test_guest_is_redirected_and_signed_in_user_can_open_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $college = $this->makeCollege('DASHACCESS');
        $user = $this->makeUserWithPermissions($college, []);

        $this->asCollege($college, $user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('College overview')
            ->assertSee('No dashboard metrics are available for this role.');
    }

    public function test_student_metric_requires_both_student_and_enrollment_view_permissions(): void
    {
        $college = $this->makeCollege('DASHPERM');
        $year = $this->makeYear($college);
        $student = $this->makeStudent($college, ['student_number' => 'DASH-PERM-1']);
        $this->makeEnrollment($college, $student, $year);

        $viewer = $this->makeUserWithPermissions($college, ['students.view', 'student_enrollments.view']);
        $studentOnly = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Total students')
            ->assertSee('data-dashboard-metric="students"', false)
            ->assertSee('Distinct students with an active enrollment in the current academic year.');

        $this->asCollege($college, $studentOnly)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('data-dashboard-metric="students"', false);
    }

    public function test_student_count_uses_distinct_active_current_year_enrollments_and_selected_college(): void
    {
        $collegeA = $this->makeCollege('DASHISOA');
        $collegeB = $this->makeCollege('DASHISOB');
        $yearA = $this->makeYear($collegeA, 'A26', '2026-A');
        $yearB = $this->makeYear($collegeB, 'B26', '2026-B');
        $oldYear = AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $collegeA->id,
            'name' => 'Previous academic year',
            'code' => 'A25',
            'starts_on' => '2025-06-01',
            'ends_on' => '2026-05-31',
            'status' => 'inactive',
        ]);
        $viewer = $this->makeUserWithPermissions($collegeA, [
            'students.view', 'student_enrollments.view', 'programs.view', 'academic-years.view',
        ]);
        $programA = $this->makeProgram($collegeA, 'BSC');
        $programB = $this->makeProgram($collegeA, 'BA');
        $programB->name = 'Arts';
        $programB->save();

        $studentOne = $this->makeStudent($collegeA, ['student_number' => 'DASH-A-1']);
        $studentTwo = $this->makeStudent($collegeA, ['student_number' => 'DASH-A-2']);
        $archived = $this->makeStudent($collegeA, ['student_number' => 'DASH-A-ARCHIVED']);
        $foreign = $this->makeStudent($collegeB, ['student_number' => 'DASH-B-1']);
        $this->makeEnrollment($collegeA, $studentOne, $yearA, $programA);
        $this->makeEnrollment($collegeA, $studentOne, $yearA, $programB);
        $this->makeEnrollment($collegeA, $studentTwo, $yearA, $programA);
        $this->makeEnrollment($collegeA, $studentTwo, $yearA, $programB, ['status' => 'withdrawn']);
        $this->makeEnrollment($collegeA, $studentTwo, $oldYear, $programA);
        $this->makeEnrollment($collegeA, $archived, $yearA, $programA);
        $archived->delete();
        $this->makeEnrollment($collegeB, $foreign, $yearB, $this->makeProgram($collegeB, 'FOREIGN'));

        $response = $this->asCollege($collegeA, $viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-dashboard-metric="students"', false)
            ->assertSee('Students by program')
            ->assertSee('BSc')
            ->assertSee('Arts')
            ->assertDontSee('DASH-B-1');
        $html = $response->getContent();

        $this->assertMatchesRegularExpression('/data-dashboard-metric="students">\s*2\s*<\/p>/s', $html);
        $this->assertStringContainsString('2026-A', $html);
    }

    public function test_faculty_and_staff_metric_counts_active_records_once_and_excludes_archived_rows(): void
    {
        $college = $this->makeCollege('DASHSTAFF');
        $otherCollege = $this->makeCollege('DASHSTAFB');
        $viewer = $this->makeUserWithPermissions($college, ['faculties.view']);
        $this->makeStaffRecord($college, 'DASH-STAFF-1', 'active');
        $this->makeStaffRecord($college, 'DASH-STAFF-2', 'active');
        $this->makeStaffRecord($college, 'DASH-STAFF-INACTIVE', 'inactive');
        $archived = $this->makeStaffRecord($college, 'DASH-STAFF-ARCHIVED', 'active');
        $archived->delete();
        $this->makeStaffRecord($otherCollege, 'DASH-STAFF-FOREIGN', 'active');

        $response = $this->asCollege($college, $viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Active faculty & staff')
            ->assertSee('HR Employee is an alias of the same Faculty table.');
        $this->assertMatchesRegularExpression('/data-dashboard-metric="faculty">\s*2\s*<\/p>/s', $response->getContent());
    }

    public function test_missing_active_academic_year_is_reported_as_unavailable_not_as_zero(): void
    {
        $college = $this->makeCollege('DASHNOYEAR');
        $viewer = $this->makeUserWithPermissions($college, ['students.view', 'student_enrollments.view']);
        $student = $this->makeStudent($college);
        // A prior inactive year must not be mistaken for the current year.
        $year = $this->makeYear($college, 'OLD26', 'Old year');
        $year->status = 'inactive';
        $year->save();
        $this->makeEnrollment($college, $student, $year);

        $this->asCollege($college, $viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-dashboard-metric="students"', false)
            ->assertSee('Unavailable')
            ->assertSee('Unavailable until this college has an active academic year.');
    }

    public function test_empty_current_year_has_a_real_zero_and_an_enrollment_empty_state(): void
    {
        $college = $this->makeCollege('DASHEMPTY');
        $this->makeYear($college);
        $viewer = $this->makeUserWithPermissions($college, [
            'students.view', 'student_enrollments.view', 'programs.view',
        ]);

        $response = $this->asCollege($college, $viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-dashboard-metric="students"', false)
            ->assertSee('No active student enrollments are recorded for this academic year.');
        $this->assertMatchesRegularExpression('/data-dashboard-metric="students">\s*0\s*<\/p>/s', $response->getContent());
    }

    public function test_new_admissions_counts_active_formal_admissions_not_application_or_student_profiles(): void
    {
        $college = $this->makeCollege('DASHADMIT');
        $year = $this->makeYear($college);
        $otherYear = AcademicYear::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'name' => 'Old year',
            'code' => 'OLD-ADMIT',
            'starts_on' => '2025-06-01',
            'ends_on' => '2026-05-31',
            'status' => 'inactive',
        ]);
        $viewer = $this->makeUserWithPermissions($college, ['admissions.view']);

        $activeApplication = $this->makeApprovedApplication($college, $year, null, 'approved');
        Admission::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'application_id' => $activeApplication->id,
            'applicant_id' => $activeApplication->applicant_id,
            'admission_number' => 'DASH-ADM-1',
            'admission_date' => '2026-08-01',
            'status' => 'active',
        ]);
        $cancelledApplication = $this->makeApprovedApplication($college, $year, null, 'approved');
        Admission::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'academic_year_id' => $year->id,
            'application_id' => $cancelledApplication->id,
            'applicant_id' => $cancelledApplication->applicant_id,
            'admission_number' => 'DASH-ADM-2',
            'admission_date' => '2026-08-02',
            'status' => 'cancelled',
        ]);
        $oldApplication = $this->makeApprovedApplication($college, $otherYear, null, 'approved');
        Admission::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'academic_year_id' => $otherYear->id,
            'application_id' => $oldApplication->id,
            'applicant_id' => $oldApplication->applicant_id,
            'admission_number' => 'DASH-ADM-3',
            'admission_date' => '2025-08-02',
            'status' => 'active',
        ]);

        $response = $this->asCollege($college, $viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('New admissions')
            ->assertSee('data-dashboard-metric="admissions"', false);
        $this->assertMatchesRegularExpression('/data-dashboard-metric="admissions">\s*1\s*<\/p>/s', $response->getContent());
    }

    public function test_pending_applications_and_admission_trend_use_submitted_workflow_rows_for_the_active_year(): void
    {
        $college = $this->makeCollege('DASHAPPS');
        $year = $this->makeYear($college);
        $viewer = $this->makeUserWithPermissions($college, ['admission_applications.view']);

        foreach ([['submitted', 1], ['under_review', 2], ['rejected', 3]] as [$status, $monthsAgo]) {
            $application = $this->makeApprovedApplication($college, $year, null, $status);
            $application->submitted_at = now()->startOfMonth()->subMonths($monthsAgo)->addDay();
            $application->save();
        }

        $response = $this->asCollege($college, $viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Pending applications')
            ->assertSee('Admission application trend')
            ->assertSee('Applications need review')
            ->assertSee('Submitted or under review in the current academic year.');
        $this->assertMatchesRegularExpression('/data-dashboard-metric="pending-applications">\s*2\s*<\/p>/s', $response->getContent());
    }

    public function test_dashboard_preserves_college_identity_header_and_existing_navigation(): void
    {
        $college = $this->makeCollege('DASHNAV');
        $viewer = $this->makeUserWithPermissions($college, ['students.view', 'student_enrollments.view']);
        $html = $this->asCollege($college, $viewer)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString($college->name, $html);
        $this->assertStringContainsString('data-nav-drawer-open', $html);
        $this->assertStringContainsString('aria-label="Notifications"', $html);
        $this->assertStringContainsString('aria-label="College ERP navigation"', $html);
        $this->assertStringContainsString('href="'.route('dashboard').'"', $html);
        $this->assertStringContainsString('href="'.route('students.index').'"', $html);
        $this->assertStringContainsString('erp-dashboard.css', $html);
    }

    private function makeStaffRecord(\App\Models\College $college, string $employeeCode, string $status): Faculty
    {
        return Faculty::withoutGlobalScopes()->create([
            'college_id' => $college->id,
            'employee_code' => $employeeCode,
            'first_name' => 'Dashboard',
            'last_name' => 'Staff',
            'status' => $status,
        ]);
    }

    public function test_super_admin_without_a_selected_college_does_not_receive_cross_college_metrics(): void
    {
        $college = $this->makeCollege('DASHPLAT');
        $year = $this->makeYear($college);
        $student = $this->makeStudent($college, ['student_number' => 'DASH-PLATFORM-1']);
        $this->makeEnrollment($college, $student, $year);
        $user = User::create([
            'name' => 'Dashboard Platform Admin',
            'email' => 'dashboard-platform-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $superAdminRole = Role::query()->where('slug', Role::SUPER_ADMIN_SLUG)->firstOrFail();
        $user->roles()->attach($superAdminRole->id, ['college_id' => null]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Platform workspace')
            ->assertSee('No dashboard metrics are available for this role.')
            ->assertDontSee('data-dashboard-metric="students"', false)
            ->assertDontSee('DASH-PLATFORM-1');
    }
}
