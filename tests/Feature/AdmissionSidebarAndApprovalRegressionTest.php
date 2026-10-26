<?php

namespace Tests\Feature;

use Tests\Feature\Departments\DepartmentTestHelpers;
use Tests\TestCase;

class AdmissionSidebarAndApprovalRegressionTest extends TestCase
{
    use DepartmentTestHelpers;

    public function test_existing_routes_remain_valid(): void
    {
        $college = $this->makeCollege('REGR1');
        $user = $this->makeUserWithPermissions($college, \App\Models\Permission::query()->pluck('slug')->all());
        $this->asCollege($college, $user)->get(route('admission-applications.index'))->assertOk();
        $this->asCollege($college, $user)->get(route('admissions.index'))->assertOk();
        $this->asCollege($college, $user)->get(route('students.index'))->assertOk();
        $this->asCollege($college, $user)->get(route('admission-reports.index'))->assertOk();
        $this->asCollege($college, $user)->get(route('admission.status-types.index'))->assertOk();
        $this->asCollege($college, $user)->get(route('approvals.pending'))->assertOk();
        $this->asCollege($college, $user)->get(route('approvals.history'))->assertOk();
        $this->asCollege($college, $user)->get(route('approvals.my-requests'))->assertOk();
        $this->asCollege($college, $user)->get(route('approvals.settings.index'))->assertOk();
        $this->asCollege($college, $user)->get(route('student-id-cards.index'))->assertOk();
        $this->asCollege($college, $user)->get(route('student-transfers.index'))->assertOk();
    }

    public function test_existing_permissions_preserved(): void
    {
        $this->assertTrue(\App\Models\Permission::query()->where('slug','admission_applications.view')->exists());
        $this->assertTrue(\App\Models\Permission::query()->where('slug','admissions.view')->exists());
        $this->assertTrue(\App\Models\Permission::query()->where('slug','approvals.view')->exists());
        $this->assertTrue(\App\Models\Permission::query()->where('slug','approvals.approve')->exists());
        $this->assertTrue(\App\Models\Permission::query()->where('slug','approvals.settings.manage')->exists());
    }

    public function test_sidebar_no_duplicate_entries(): void
    {
        $college = $this->makeCollege('REGR2');
        $user = $this->makeUserWithPermissions($college, \App\Models\Permission::query()->pluck('slug')->all());
        $html = $this->asCollege($college, $user)->get(route('dashboard'))->assertOk()->getContent();
        $aside = $this->aside($html);
        preg_match_all('/<a class="nav-(?:link|dashboard)" href="([^"]+)"/', $aside, $m);
        $hrefs = $m[1];
        $this->assertCount(count(array_unique($hrefs)), $hrefs, 'No duplicate hrefs in sidebar');
        $this->assertStringNotContainsString('data-nav-group="admission"', $aside, 'The standalone Admission menu must stay removed.');
    }

    public function test_unrelated_modules_still_work(): void
    {
        $college = $this->makeCollege('REGR3');
        $user = $this->makeUserWithPermissions($college, \App\Models\Permission::query()->pluck('slug')->all());
        $this->asCollege($college, $user)->get(route('finance-reports.index'))->assertOk();
        $this->asCollege($college, $user)->get(route('library-reports.index'))->assertOk();
        $this->asCollege($college, $user)->get(route('hr-reports.index'))->assertOk();
    }

    private function aside(string $html): string { $s=strpos($html,'<aside'); $e=strpos($html,'</aside>',$s); return substr($html,$s,$e-$s); }
}
