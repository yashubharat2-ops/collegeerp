<?php

namespace App\Policies;

use App\Models\FeePayment;
use App\Models\User;

/**
 * Fee Collection authorization (Finance / Fees).
 *
 * Recording, correcting and reversing money are separate abilities:
 *
 *   fee_collections.view    → see the collection register
 *   fee_collections.create  → record a collection
 *   fee_collections.update  → correct descriptive fields AND reverse a payment
 *   fee_collections.delete  → soft-delete a data-entry mistake
 *
 * A reversal (cancel) is deliberately governed by fee_collections.update: it is
 * a correction of an existing collection, and every reversal is audit-logged
 * with the actor, time and reason.
 */
class FeePaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('fee_collections.view');
    }

    public function view(User $user, FeePayment $payment): bool
    {
        return $user->hasPermission('fee_collections.view', $payment->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('fee_collections.create');
    }

    public function update(User $user, FeePayment $payment): bool
    {
        return $user->hasPermission('fee_collections.update', $payment->college_id);
    }

    public function cancel(User $user, FeePayment $payment): bool
    {
        return $user->hasPermission('fee_collections.update', $payment->college_id);
    }

    public function delete(User $user, FeePayment $payment): bool
    {
        return $user->hasPermission('fee_collections.delete', $payment->college_id);
    }
}
