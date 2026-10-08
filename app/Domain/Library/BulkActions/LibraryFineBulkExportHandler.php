<?php

namespace App\Domain\Library\BulkActions;

use App\Models\LibraryFine;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected library fines (Library — Fines / Penalties).
 *
 * Each ticked fine is re-queried inside the active college and re-authorized
 * through {@see \App\Policies\LibraryFinePolicy} (`library_fines.view`). The
 * CSV carries the member, book, fine type, period, assessed / paid /
 * outstanding amounts and the status — money figures as stored, never
 * recalculated. No fine is assessed, waived, modified or paid by an export;
 * the free-text payment reference and remarks stay off the CSV.
 */
class LibraryFineBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return LibraryFine::class;
    }

    public function requiredPermission(): ?string
    {
        return 'library_fines.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'library-fines.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'fine' : 'fines';
    }
}
