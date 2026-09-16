<?php

namespace App\Policies;

use App\Models\AdmissionDocument;
use App\Models\User;

class AdmissionDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admission_documents.view');
    }

    public function view(User $user, AdmissionDocument $document): bool
    {
        return $user->hasPermission('admission_documents.view', $document->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('admission_documents.create');
    }

    public function update(User $user, AdmissionDocument $document): bool
    {
        return $user->hasPermission('admission_documents.update', $document->college_id);
    }

    public function delete(User $user, AdmissionDocument $document): bool
    {
        return $user->hasPermission('admission_documents.delete', $document->college_id);
    }

    public function verify(User $user, AdmissionDocument $document): bool
    {
        return $user->hasPermission('admission_documents.verify', $document->college_id);
    }

    public function download(User $user, AdmissionDocument $document): bool
    {
        // Same as view, but explicit for clarity
        return $user->hasPermission('admission_documents.view', $document->college_id);
    }
}
