<?php

namespace App\Domain\Finance\BulkActions;

use App\Models\FeePayment;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected receipts (Finance — Receipts).
 *
 * A receipt is a derived view of one successful collection, so the selectable record is that
 * FeePayment row: it is re-queried inside the active college and re-authorized through
 * {@see \App\Policies\FeePaymentPolicy} (`fee_collections.view`), which is what
 * FeeReceiptPolicy guards per row on the screen. Receipts are read-only by design (no
 * create/update/delete routes exist), so the whole selection family is export only.
 * Read-only by construction: the export endpoint re-queries the ticked ids inside the active college and the CSV carries the same columns the listing shows — no money value is recalculated here, and no payment, receipt, refund, concession, assignment or ledger row is written.
 */
class ReceiptBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return FeePayment::class;
    }

    public function requiredPermission(): ?string
    {
        return 'receipts.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'receipts.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'receipt' : 'receipts';
    }
}
