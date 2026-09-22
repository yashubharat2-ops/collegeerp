<?php

namespace App\Policies;

use App\Models\LibraryTransaction;
use App\Models\User;

/**
 * Issue / return authorization (Library Management).
 *
 *   library_transactions.view    → see the circulation register
 *   library_transactions.create  → issue an available copy
 *   library_transactions.update  → correct remarks only
 *   library_transactions.return  → return a copy, or mark the loan lost
 *
 * There is no delete ability: circulation history is not removed.
 */
class LibraryTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('library_transactions.view');
    }

    public function view(User $user, LibraryTransaction $transaction): bool
    {
        return $user->hasPermission('library_transactions.view', $transaction->college_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('library_transactions.create');
    }

    public function update(User $user, LibraryTransaction $transaction): bool
    {
        return $user->hasPermission('library_transactions.update', $transaction->college_id);
    }

    public function returnCopy(User $user, LibraryTransaction $transaction): bool
    {
        return $user->hasPermission('library_transactions.return', $transaction->college_id);
    }

    public function markLost(User $user, LibraryTransaction $transaction): bool
    {
        return $user->hasPermission('library_transactions.return', $transaction->college_id);
    }

    public function delete(User $user, LibraryTransaction $transaction): bool
    {
        return false;
    }
}
