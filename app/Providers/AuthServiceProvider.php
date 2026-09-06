<?php
namespace App\Providers;
use App\Models\{AcademicYear, Campus, College, Department, InstitutionalSetting, Permission, Role};
use App\Policies\{AcademicYearPolicy, CampusPolicy, CollegePolicy, DepartmentPolicy, InstitutionalSettingPolicy, PermissionPolicy, RolePolicy};
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [College::class => CollegePolicy::class, Campus::class => CampusPolicy::class, Department::class => DepartmentPolicy::class, AcademicYear::class => AcademicYearPolicy::class, InstitutionalSetting::class => InstitutionalSettingPolicy::class, Role::class => RolePolicy::class, Permission::class => PermissionPolicy::class];
    public function boot(): void {}
}
