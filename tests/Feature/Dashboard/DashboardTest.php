<?php

namespace Tests\Feature\Dashboard;

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
            ->assertSee('College overview');
    }

    public function test_student_metric_is_rendered_only_with_student_view_permission(): void
    {
        $college = $this->makeCollege('DASHPERM');
        $viewer = $this->makeUserWithPermissions($college, ['students.view']);
        $withoutPermission = $this->makeUserWithPermissions($college, []);
        $this->makeStudent($college, ['student_number' => 'DASH-PERM-1']);

        $this->asCollege($college, $viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Student records')
            ->assertSee('data-dashboard-student-count>1</p>', false)
            ->assertSee('Browse student records');

        $this->asCollege($college, $withoutPermission)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Student totals are available to users with student-view access')
            ->assertDontSee('data-dashboard-student-count', false)
            ->assertDontSee('Browse student records');
    }

    public function test_student_metric_counts_non_archived_records_in_the_active_college_only(): void
    {
        $collegeA = $this->makeCollege('DASHISOA');
        $collegeB = $this->makeCollege('DASHISOB');
        $viewer = $this->makeUserWithPermissions($collegeA, ['students.view']);

        $this->makeStudent($collegeA, ['student_number' => 'DASH-A-ACTIVE', 'status' => 'active']);
        $this->makeStudent($collegeA, ['student_number' => 'DASH-A-INACTIVE', 'status' => 'inactive']);
        $archived = $this->makeStudent($collegeA, ['student_number' => 'DASH-A-ARCHIVED']);
        $archived->delete();
        $this->makeStudent($collegeB, ['student_number' => 'DASH-B-RECORD']);

        $this->asCollege($collegeA, $viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-dashboard-student-count>2</p>', false)
            ->assertSee('Non-archived student profiles, across all statuses.');
    }

    public function test_empty_college_shows_a_real_zero_and_a_clear_empty_state(): void
    {
        $college = $this->makeCollege('DASHEMPTY');
        $viewer = $this->makeUserWithPermissions($college, ['students.view']);

        $this->asCollege($college, $viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-dashboard-student-count>0</p>', false)
            ->assertSee('No student records have been added to this college yet.');
    }

    public function test_dashboard_keeps_college_identity_header_and_navigation_controls(): void
    {
        $college = $this->makeCollege('DASHNAV');
        $viewer = $this->makeUserWithPermissions($college, ['students.view']);
        $html = $this->asCollege($college, $viewer)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString($college->name, $html);
        $this->assertStringContainsString('data-nav-drawer-open', $html);
        $this->assertStringContainsString('aria-label="Notifications"', $html);
        $this->assertStringContainsString('aria-label="College ERP navigation"', $html);
        $this->assertStringContainsString('href="'.route('dashboard').'"', $html);
        $this->assertStringContainsString('href="'.route('students.index').'"', $html);
        $this->assertStringContainsString('erp-dashboard.css', $html);
    }

    public function test_super_admin_without_a_selected_college_does_not_receive_cross_college_metrics(): void
    {
        $college = $this->makeCollege('DASHPLAT');
        $this->makeStudent($college, ['student_number' => 'DASH-PLATFORM-1']);
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
            ->assertDontSee('data-dashboard-student-count', false)
            ->assertDontSee('DASH-PLATFORM-1');
    }
}
