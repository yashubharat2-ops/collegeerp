<?php

namespace App\Policies;

use App\Models\FeeReceipt;
use App\Models\User;

/**
 * Receipt authorization (Finance / Fees).
 *
 * A receipt is a printable projection of a successful FeePayment, so the policy
 * has two dedicated permissions and one hard rule:
 *
 *   receipts.view  → list receipts and open a receipt
 *   receipts.print → open the print-friendly receipt page
 *
 * A receipt must NEVER show a payment that is not money: a cancelled/reversed
 * collection, a collection still awaiting approval, or one an approver rejected.
 * That rule is enforced here (not by the caller), on top of tenant isolation,
 * which is enforced upstream by the tenant-scoped FeePayment query.
 */
class FeeReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('receipts.view');
    }

    public function view(User $user, FeeReceipt $receipt): bool
    {
        return $this->allowed($user, $receipt);
    }

    public function print(User $user, FeeReceipt $receipt): bool
    {
        $payment = $receipt->payment;

        return $user->hasPermission('receipts.print', $payment->college_id)
            && $payment->countsTowardsPaid();
    }

    private function allowed(User $user, FeeReceipt $receipt): bool
    {
        $payment = $receipt->payment;

        // countsTowardsPaid() is completed-only, so a pending, rejected or
        // cancelled collection is never exposed as a receipt.
        return $user->hasPermission('receipts.view', $payment->college_id)
            && $payment->countsTowardsPaid();
    }
}
