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
 * A receipt must NEVER show a cancelled/reversed payment: that rule is enforced
 * here (not by the caller), on top of tenant isolation, which is enforced
 * upstream by the tenant-scoped FeePayment query.
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
            && ! $payment->isCancelled();
    }

    private function allowed(User $user, FeeReceipt $receipt): bool
    {
        $payment = $receipt->payment;

        return $user->hasPermission('receipts.view', $payment->college_id)
            && ! $payment->isCancelled();
    }
}
