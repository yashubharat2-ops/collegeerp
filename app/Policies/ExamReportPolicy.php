<?php

namespace App\Policies;

use App\Models\User;

/**
 * Exam Reports authorization (Examinations Phase 4).
 *
 * The reports screen aggregates published ExamResult / ExamResultItem data and
 * has no per-row resource of its own, so the policy is a single screen-level
 * permission:
 *
 *   exam_reports.view → view the examination reports
 *
 * Published-only and tenant-safe access is enforced by the underlying report
 * queries (tenant-scoped models plus explicit published guards), never by
 * the caller.
 */
class ExamReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('exam_reports.view');
    }
}
