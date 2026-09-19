<?php

namespace App\Policies;

use App\Models\StudentDocument;
use App\Models\User;

/**
 * RBAC for student documents.
 *
 * Permission set is intentionally small (view / create / update / delete):
 * verification is an `update`-level action and download is a `view`-level
 * action, mirroring how AdmissionDocumentPolicy exposes `verify`/`download`
 * without inflating the permission catalogue.
 */
class StudentDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('student_documents.view');
    }

    public function view(User $user, StudentDocument $document): bool
    {
        return $user->hasPermission('student_documents.view', $document->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('student_documents.create');
    }

    public function update(User $user, StudentDocument $document): bool
    {
        return $user->hasPermission('student_documents.update', $document->college_id);
    }

    public function delete(User $user, StudentDocument $document): bool
    {
        return $user->hasPermission('student_documents.delete', $document->college_id);
    }

    /** Verification/rejection is an update-level action. */
    public function verify(User $user, StudentDocument $document): bool
    {
        return $user->hasPermission('student_documents.update', $document->college_id);
    }

    /** Downloads require the same permission as viewing the record. */
    public function download(User $user, StudentDocument $document): bool
    {
        return $user->hasPermission('student_documents.view', $document->college_id);
    }
}
