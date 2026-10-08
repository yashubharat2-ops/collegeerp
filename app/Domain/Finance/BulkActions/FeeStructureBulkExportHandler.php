<?php

namespace App\Domain\Finance\BulkActions;

use App\Models\FeeStructure;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected fee structures (Finance — Fee Structures).
 *
 * Each ticked structure is re-queried inside the active college and re-authorized through
 * {@see \App\Policies\FeeStructurePolicy} (`fee_structures.view`). Fee structures are edited
 * one plan at a time — their components are validated as a whole by FeeStructureService — so
 * this screen is export-only.
 * Read-only by construction: the export endpoint re-queries the ticked ids inside the active college and the CSV carries the same columns the listing shows — no money value is recalculated here, and no payment, receipt, refund, concession, assignment or ledger row is written.
 */
class FeeStructureBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return FeeStructure::class;
    }

    public function requiredPermission(): ?string
    {
        return 'fee_structures.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'fee-structures.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'fee structure' : 'fee structures';
    }
}
