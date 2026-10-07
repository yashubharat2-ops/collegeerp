<?php

namespace App\Domain\Finance\BulkActions;

use App\Models\FeeConcession;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected fee concessions / discounts (Finance — Concessions).
 *
 * Each ticked concession is re-queried inside the active college and re-authorized through
 * {@see \App\Policies\FeeConcessionPolicy} (`fee_concessions.view`). Approving a concession
 * changes what a student owes, so it keeps its own permitted action with its own audit trail
 * — bulk here is export only.
 * Read-only by construction: the export endpoint re-queries the ticked ids inside the active college and the CSV carries the same columns the listing shows — no money value is recalculated here, and no payment, receipt, refund, concession, assignment or ledger row is written.
 */
class FeeConcessionBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return FeeConcession::class;
    }

    public function requiredPermission(): ?string
    {
        return 'fee_concessions.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'fee-concessions.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'fee concession' : 'fee concessions';
    }
}
