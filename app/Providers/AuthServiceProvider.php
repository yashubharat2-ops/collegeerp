<?php
namespace App\Providers;
use App\Models\{AcademicYear, Admission, AdmissionApplicant, AdmissionApplication, AdmissionDocument, AdmissionDocumentType, AdmissionEnquiry, AdmissionMeritEntry, AdmissionMeritList, Campus, College, Department, InstitutionalSetting, Permission, Program, Role};
use App\Policies\{AcademicYearPolicy, AdmissionApplicantPolicy, AdmissionApplicationPolicy, AdmissionDocumentPolicy, AdmissionDocumentTypePolicy, AdmissionEnquiryPolicy, AdmissionMeritEntryPolicy, AdmissionMeritListPolicy, AdmissionPolicy, CampusPolicy, CollegePolicy, DepartmentPolicy, InstitutionalSettingPolicy, PermissionPolicy, ProgramPolicy, RolePolicy};
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [College::class => CollegePolicy::class, Campus::class => CampusPolicy::class, Department::class => DepartmentPolicy::class, AcademicYear::class => AcademicYearPolicy::class, Program::class => ProgramPolicy::class, InstitutionalSetting::class => InstitutionalSettingPolicy::class, Role::class => RolePolicy::class, Permission::class => PermissionPolicy::class, AdmissionApplicant::class => AdmissionApplicantPolicy::class, AdmissionEnquiry::class => AdmissionEnquiryPolicy::class, AdmissionApplication::class => AdmissionApplicationPolicy::class, AdmissionDocumentType::class => AdmissionDocumentTypePolicy::class, AdmissionDocument::class => AdmissionDocumentPolicy::class, AdmissionMeritList::class => AdmissionMeritListPolicy::class, AdmissionMeritEntry::class => AdmissionMeritEntryPolicy::class, Admission::class => AdmissionPolicy::class];
    public function boot(): void {}
}
