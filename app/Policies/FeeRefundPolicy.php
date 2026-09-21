<?php

namespace App\Policies;

use App\Models\FeeRefund;
use App\Models\User;

/**
 * Refunds authorization (Finance / Fees).
 *
 *   refunds.view    → see the refund register
 *   refunds.create  → raise a refund against a collected payment
 *   refunds.update  → correct it, reject/cancel it, or mark an approved refund
 *                     as processed (money actually handed back)
 *   refunds.approve → approve it
 *
 * Approval is deliberately separate from raising the refund, and only an
 * approved refund can be processed.
 */
class FeeRefundPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('refunds.view');
    }

    public function view(User $user, FeeRefund $refund): bool
    {
        return $user->hasPermission('refunds.view', $refund->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('refunds.create');
    }

    public function update(User $user, FeeRefund $refund): bool
    {
        return $user->hasPermission('refunds.update', $refund->college_id);
    }

    public function approve(User $user, FeeRefund $refund): bool
    {
        return $user->hasPermission('refunds.approve', $refund->college_id);
    }

    public function process(User $user, FeeRefund $refund): bool
    {
        return $user->hasPermission('refunds.update', $refund->college_id);
    }
}
