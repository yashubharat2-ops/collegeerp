<?php

namespace App\Domain\Finance\BulkActions;

use App\Models\FeePayment;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected fee collections (Finance — Fee Collection).
 *
 * Each ticked payment is re-queried inside the active college and re-authorized through
 * {@see \App\Policies\FeePaymentPolicy} (`fee_collections.view`). Collecting money, editing a
 * payment or cancelling a receipt stays a single-record workflow with its own controller
 * action, service rules and audit trail, so bulk here is export only.
 * Read-only by construction: the export endpoint re-queries the ticked ids inside the active college and the CSV carries the same columns the listing shows — no money value is recalculated here, and no payment, receipt, refund, concession, assignment or ledger row is written.
 */
class FeeCollectionBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return FeePayment::class;
    }

    public function requiredPermission(): ?string
    {
        return 'fee_collections.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'fee-collections.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'fee collection' : 'fee collections';
    }
}
