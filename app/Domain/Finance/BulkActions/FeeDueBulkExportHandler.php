<?php

namespace App\Domain\Finance\BulkActions;

use App\Models\StudentFeeAssignment;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected due / outstanding lines (Finance — Due / Outstanding Fees).
 *
 * The dues screen has no rows of its own: every line is a live ledger over one
 * StudentFeeAssignment, which is what the selection posts. Each id is re-queried inside the
 * active college and re-authorized through {@see \App\Policies\StudentFeeAssignmentPolicy},
 * and the endpoint rebuilds the same ledger for exactly those ids through FeeDuesService — no
 * balance is recalculated by hand and nothing is written.
 * Read-only by construction: the export endpoint re-queries the ticked ids inside the active college and the CSV carries the same columns the listing shows — no money value is recalculated here, and no payment, receipt, refund, concession, assignment or ledger row is written.
 */
class FeeDueBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return StudentFeeAssignment::class;
    }

    public function requiredPermission(): ?string
    {
        return 'fee_dues.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'fee-dues.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'due row' : 'due rows';
    }
}
