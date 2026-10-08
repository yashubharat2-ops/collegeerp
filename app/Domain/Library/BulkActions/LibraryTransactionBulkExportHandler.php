<?php

namespace App\Domain\Library\BulkActions;

use App\Models\LibraryTransaction;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected circulation records (Library — Issue / Return).
 *
 * Each ticked issue is re-queried inside the active college and re-authorized
 * through {@see \App\Policies\LibraryTransactionPolicy}
 * (`library_transactions.view`). The CSV carries the accession, title, member,
 * issue / due / return dates, the renewal count and the status — the columns
 * the listing shows. Circulation history is append-only: an export never
 * issues, returns or marks a copy lost.
 */
class LibraryTransactionBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return LibraryTransaction::class;
    }

    public function requiredPermission(): ?string
    {
        return 'library_transactions.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'library-transactions.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'circulation record' : 'circulation records';
    }
}
