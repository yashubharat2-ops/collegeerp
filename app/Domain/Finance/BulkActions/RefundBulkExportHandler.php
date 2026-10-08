<?php

namespace App\Domain\Finance\BulkActions;

use App\Models\FeeRefund;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected refunds (Finance — Refunds).
 *
 * Each ticked refund is re-queried inside the active college and re-authorized through
 * {@see \App\Policies\FeeRefundPolicy} (`refunds.view`). Approving and processing a refund
 * move money and remain single-record workflows handled by FeeRefundService, so the bulk
 * selection is export only.
 * Read-only by construction: the export endpoint re-queries the ticked ids inside the active college and the CSV carries the same columns the listing shows — no money value is recalculated here, and no payment, receipt, refund, concession, assignment or ledger row is written.
 */
class RefundBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return FeeRefund::class;
    }

    public function requiredPermission(): ?string
    {
        return 'refunds.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'refunds.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'refund' : 'refunds';
    }
}
