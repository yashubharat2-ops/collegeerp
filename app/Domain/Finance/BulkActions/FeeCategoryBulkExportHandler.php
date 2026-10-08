<?php

namespace App\Domain\Finance\BulkActions;

use App\Models\FeeCategory;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected fee categories (Finance — Fee Categories).
 *
 * Each ticked category is re-queried inside the active college and re-authorized through
 * {@see \App\Policies\FeeCategoryPolicy} (`fee_categories.view`). Categories classify fee
 * heads; there is no bulk rename/retire, so the selection only ever exports.
 * Read-only by construction: the export endpoint re-queries the ticked ids inside the active college and the CSV carries the same columns the listing shows — no money value is recalculated here, and no payment, receipt, refund, concession, assignment or ledger row is written.
 */
class FeeCategoryBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return FeeCategory::class;
    }

    public function requiredPermission(): ?string
    {
        return 'fee_categories.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'fee-categories.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'fee category' : 'fee categories';
    }
}
