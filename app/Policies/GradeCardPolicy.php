<?php

namespace App\Policies;

use App\Models\GradeCard;
use App\Models\User;

/**
 * Grade Cards authorization (Examinations Phase 4).
 *
 * Grade cards are derived, read-only documents rendered from published
 * ExamResult data, so the policy has a single dedicated permission and a
 * hard published-only rule:
 *
 *   grade_cards.view → list published results and view/print their grade card
 *
 * An unpublished, draft, soft-deleted or foreign-college result can never
 * satisfy `view()`: unpublished rows are rejected here, while tenant
 * isolation and soft-delete exclusion are enforced upstream by the
 * tenant-scoped ExamResult query (CollegeScope + SoftDeletes), which turns
 * cross-college or deleted ids into a 404 before the policy is reached.
 */
class GradeCardPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('grade_cards.view');
    }

    /**
     * A grade card is viewable only when the user holds the grade-card
     * permission for the result's own college AND the result is published.
     */
    public function view(User $user, GradeCard $gradeCard): bool
    {
        $result = $gradeCard->result;

        if (! $user->hasPermission('grade_cards.view', $result->college_id)) {
            return false;
        }

        return $result->isPublished();
    }
}
