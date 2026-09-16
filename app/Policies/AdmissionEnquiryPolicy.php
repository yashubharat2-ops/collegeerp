<?php

namespace App\Policies;

use App\Models\AdmissionEnquiry;
use App\Models\User;

class AdmissionEnquiryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admission_enquiries.view');
    }

    public function view(User $user, AdmissionEnquiry $enquiry): bool
    {
        return $user->hasPermission('admission_enquiries.view', $enquiry->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('admission_enquiries.create');
    }

    public function update(User $user, AdmissionEnquiry $enquiry): bool
    {
        return $user->hasPermission('admission_enquiries.update', $enquiry->college_id);
    }

    public function delete(User $user, AdmissionEnquiry $enquiry): bool
    {
        return $user->hasPermission('admission_enquiries.delete', $enquiry->college_id);
    }
}
