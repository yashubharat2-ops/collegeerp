<?php

namespace App\Policies;

use App\Models\EmployeeDocument;
use App\Models\User;

class EmployeeDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('employee_documents.view');
    }

    public function view(User $user, EmployeeDocument $document): bool
    {
        return $user->hasPermission('employee_documents.view', $document->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('employee_documents.create');
    }

    public function update(User $user, EmployeeDocument $document): bool
    {
        return $user->hasPermission('employee_documents.update', $document->college_id);
    }

    public function delete(User $user, EmployeeDocument $document): bool
    {
        return $user->hasPermission('employee_documents.delete', $document->college_id);
    }

    /** Downloads are authorized like views; the private disk is never public. */
    public function download(User $user, EmployeeDocument $document): bool
    {
        return $user->hasPermission('employee_documents.view', $document->college_id);
    }
}
