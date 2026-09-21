<?php

namespace App\Policies;

use App\Models\Marksheet;
use App\Models\User;

/**
 * Marksheets authorization (Examinations Phase 4A).
 *
 * Marksheets are derived, read-only documents rendered from published
 * ExamResult data, so the policy has a single dedicated permission and a
 * hard published-only rule:
 *
 *   marksheets.view → list published results and view/print their marksheet
 *
 * An unpublished, draft, soft-deleted or foreign-college result can never
 * satisfy `view()`: unpublished rows are rejected here, while tenant
 * isolation and soft-delete exclusion are enforced upstream by the
 * tenant-scoped ExamResult query (CollegeScope + SoftDeletes), which turns
 * cross-college or deleted ids into a 404 before the policy is reached.
 */
class MarksheetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('marksheets.view');
    }

    /**
     * A marksheet is viewable only when the user holds the marksheet
     * permission for the result's own college AND the result is published.
     */
    public function view(User $user, Marksheet $marksheet): bool
    {
        $result = $marksheet->result;

        if (! $user->hasPermission('marksheets.view', $result->college_id)) {
            return false;
        }

        return $result->isPublished();
    }
}
