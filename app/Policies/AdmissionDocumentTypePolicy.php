<?php

namespace App\Policies;

use App\Models\AdmissionDocumentType;
use App\Models\User;

class AdmissionDocumentTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admission_documents.view') || $user->hasPermission('admission_document_types.view');
    }

    public function view(User $user, AdmissionDocumentType $type): bool
    {
        return $user->hasPermission('admission_documents.view', $type->college_id)
            || $user->hasPermission('admission_document_types.view', $type->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('admission_documents.create') || $user->hasPermission('admission_document_types.create');
    }

    public function update(User $user, AdmissionDocumentType $type): bool
    {
        return $user->hasPermission('admission_documents.update', $type->college_id)
            || $user->hasPermission('admission_document_types.update', $type->college_id);
    }

    public function delete(User $user, AdmissionDocumentType $type): bool
    {
        return $user->hasPermission('admission_documents.delete', $type->college_id)
            || $user->hasPermission('admission_document_types.delete', $type->college_id);
    }
}
