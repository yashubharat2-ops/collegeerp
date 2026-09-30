<?php

namespace App\Policies;

use App\Models\InstitutionalSetting;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

class InstitutionalSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return app(TenantContext::class)->has() && $user->hasPermission('settings.view');
    }

    public function manage(User $user): bool
    {
        return app(TenantContext::class)->has() && $user->hasPermission('settings.update');
    }

    public function update(User $user, InstitutionalSetting $setting): bool
    {
        return (int) $setting->college_id === app(TenantContext::class)->id() && $this->manage($user);
    }

    public function viewAcademicConfiguration(User $user): bool
    {
        return app(TenantContext::class)->has() && collect([
            'academic-years.view', 'academic_terms.view', 'departments.view',
            'programs.view', 'sections.view', 'subjects.view', 'campuses.view',
            'faculty_subject_assignments.view', 'grade_scales.view',
        ])->contains(fn (string $permission) => $user->hasPermission($permission));
    }

    public function viewNotifications(User $user): bool
    {
        return app(TenantContext::class)->has() && (
            $user->hasPermission('settings.view')
            || $user->hasPermission('notifications.view')
            || $user->hasPermission('communication_templates.view')
        );
    }

    public function viewSystem(User $user): bool
    {
        return (app(TenantContext::class)->has() || $user->isSuperAdmin()) && $user->hasPermission('settings.view');
    }

    public function viewBranding(User $user): bool
    {
        $collegeId = app(TenantContext::class)->id();

        return $user->is_active && $collegeId !== null
            && ($user->isSuperAdmin() || $user->colleges()->whereKey($collegeId)->exists());
    }
}
