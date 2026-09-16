<?php

namespace App\Policies;

use App\Models\AdmissionApplicant;
use App\Models\User;

class AdmissionApplicantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admission_applicants.view');
    }

    public function view(User $user, AdmissionApplicant $applicant): bool
    {
        return $user->hasPermission('admission_applicants.view', $applicant->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('admission_applicants.create');
    }

    public function update(User $user, AdmissionApplicant $applicant): bool
    {
        return $user->hasPermission('admission_applicants.update', $applicant->college_id);
    }

    public function delete(User $user, AdmissionApplicant $applicant): bool
    {
        return $user->hasPermission('admission_applicants.delete', $applicant->college_id);
    }
}
