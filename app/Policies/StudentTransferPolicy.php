<?php

namespace App\Policies;

use App\Models\StudentTransfer;
use App\Models\User;

/**
 * RBAC for student transfers / TC.
 *
 * Permission slugs: view, create, update, approve. The workflow capabilities
 * map onto them without inflating the catalogue:
 *
 * - approve  → approving a request
 * - issue    → issuing the TC (approve-level: it mints the number and changes
 *              student/enrollment statuses)
 * - cancel   → withdrawing a request (approve-level)
 * - download → reading the TC file (view-level, same as AdmissionDocument)
 * - delete   → removing a not-yet-issued request (update-level)
 *
 * Every check is scoped to the record's own college_id.
 */
class StudentTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('student_transfers.view');
    }

    public function view(User $user, StudentTransfer $transfer): bool
    {
        return $user->hasPermission('student_transfers.view', $transfer->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('student_transfers.create');
    }

    public function update(User $user, StudentTransfer $transfer): bool
    {
        return $user->hasPermission('student_transfers.update', $transfer->college_id);
    }

    public function approve(User $user, StudentTransfer $transfer): bool
    {
        return $user->hasPermission('student_transfers.approve', $transfer->college_id);
    }

    public function issue(User $user, StudentTransfer $transfer): bool
    {
        return $user->hasPermission('student_transfers.approve', $transfer->college_id);
    }

    public function cancel(User $user, StudentTransfer $transfer): bool
    {
        return $user->hasPermission('student_transfers.approve', $transfer->college_id);
    }

    public function download(User $user, StudentTransfer $transfer): bool
    {
        return $user->hasPermission('student_transfers.view', $transfer->college_id);
    }

    public function delete(User $user, StudentTransfer $transfer): bool
    {
        return $user->hasPermission('student_transfers.update', $transfer->college_id);
    }
}
