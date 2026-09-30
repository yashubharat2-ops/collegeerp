<?php

namespace App\Policies;

use App\Models\User;

/**
 * Consolidated Reports authorization.
 *
 * The screen aggregates existing records and has no per-row resource of its
 * own, so the policy is a single screen-level permission:
 * `consolidated_reports.view`. It is granted to Super Admin and College Admin
 * by the centralized DatabaseSeeder.
 *
 * The permission is deliberately separate from every operational permission of
 * the modules it summarizes (students.*, academic_reports.*, fee_collections.*,
 * hr_reports.*, library_reports.*, transport_reports.*, hostel_reports.*,
 * inventory_reports.*, communication_reports.*, certificate_reports.*, …): read
 * access to the consolidated figures never implies access to the operational
 * screens (or the other report modules), and vice versa. The module itself is
 * read-only.
 */
class ConsolidatedReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('consolidated_reports.view');
    }
}
