<?php
namespace App\Providers;
use App\Models\{AcademicYear, AdmissionApplicant, AdmissionApplication, AdmissionEnquiry, Campus, College, Department, InstitutionalSetting, Permission, Program, Role};
use App\Policies\{AcademicYearPolicy, AdmissionApplicantPolicy, AdmissionApplicationPolicy, AdmissionEnquiryPolicy, CampusPolicy, CollegePolicy, DepartmentPolicy, InstitutionalSettingPolicy, PermissionPolicy, ProgramPolicy, RolePolicy};
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [College::class => CollegePolicy::class, Campus::class => CampusPolicy::class, Department::class => DepartmentPolicy::class, AcademicYear::class => AcademicYearPolicy::class, Program::class => ProgramPolicy::class, InstitutionalSetting::class => InstitutionalSettingPolicy::class, Role::class => RolePolicy::class, Permission::class => PermissionPolicy::class, AdmissionApplicant::class => AdmissionApplicantPolicy::class, AdmissionEnquiry::class => AdmissionEnquiryPolicy::class, AdmissionApplication::class => AdmissionApplicationPolicy::class];
    public function boot(): void {}
}
