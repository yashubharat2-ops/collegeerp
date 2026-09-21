<?php

namespace App\Policies;

use App\Models\ExamResult;
use App\Models\User;

/**
 * Results / Result Calculation / Result Publishing authorization
 * (Examinations Phase 3).
 *
 * One policy covers the three related capabilities because they act on the same
 * ExamResult resource, but each keeps its own dedicated permission slug:
 *
 *   results.view               → see PUBLISHED calculated results
 *   results.view_unpublished   → additionally see unpublished results
 *   result_calculation.*       → calculate / recalculate
 *   result_publishing.*        → publish / unpublish
 *
 * "unpublish only if explicitly allowed by authorization" is enforced by the
 * dedicated result_publishing.unpublish permission — never by the UI alone.
 */
class ExamResultPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('results.view');
    }

    /**
     * A single result is readable when the user may see results at all, and —
     * when it is not published — when they additionally hold the unpublished
     * visibility permission.
     */
    public function view(User $user, ExamResult $examResult): bool
    {
        if (! $user->hasPermission('results.view', $examResult->college_id)) {
            return false;
        }

        if ($examResult->isUnpublished()) {
            return $this->viewUnpublished($user, $examResult);
        }

        return true;
    }

    /**
     * The exam result argument is optional so the ability can also be checked at
     * class level — the Results list needs to know once whether unpublished rows
     * may be included at all.
     */
    public function viewUnpublished(User $user, ?ExamResult $examResult = null): bool
    {
        return $user->hasPermission('results.view_unpublished', $examResult?->college_id);
    }

    /**
     * The Result Calculation screen.
     */
    public function viewCalculation(User $user): bool
    {
        return $user->hasPermission('result_calculation.view');
    }

    /**
     * The Result Publishing screen.
     */
    public function viewPublishing(User $user): bool
    {
        return $user->hasPermission('result_publishing.view');
    }

    public function calculate(User $user): bool
    {
        return $user->hasPermission('result_calculation.calculate');
    }

    public function recalculate(User $user): bool
    {
        return $user->hasPermission('result_calculation.recalculate');
    }

    /**
     * The exam result argument is optional so the ability can also be checked
     * at class level (`@can('publish', ExamResult::class)`) for bulk actions.
     */
    public function publish(User $user, ?ExamResult $examResult = null): bool
    {
        return $user->hasPermission('result_publishing.publish', $examResult?->college_id);
    }

    public function unpublish(User $user, ?ExamResult $examResult = null): bool
    {
        return $user->hasPermission('result_publishing.unpublish', $examResult?->college_id);
    }
}
