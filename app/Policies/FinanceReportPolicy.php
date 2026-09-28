<?php

namespace App\Policies;

use App\Models\User;

/**
 * Finance Reports authorization.
 *
 * The reporting screen aggregates existing records and has no per-row resource
 * of its own, so the policy is a single screen-level permission:
 * `finance_reports.view`. It is granted to Super Admin and College Admin by the
 * centralized DatabaseSeeder.
 *
 * The permission is deliberately separate from the operational Finance / Fees
 * permissions (fee_collections.*, fee_dues.view, receipts.*, refunds.*, …) and
 * from the pre-existing fee_reports.view screen: read access to reports never
 * implies access to move money, and vice versa.
 *
 * Nothing on the screen writes — there is no create/update/delete policy method.
 */
class FinanceReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('finance_reports.view');
    }
}
