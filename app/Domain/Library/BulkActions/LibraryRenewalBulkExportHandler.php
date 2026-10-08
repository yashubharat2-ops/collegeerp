<?php

namespace App\Domain\Library\BulkActions;

use App\Models\LibraryRenewal;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected renewal records (Library — Renewals).
 *
 * Each ticked renewal is re-queried inside the active college and
 * re-authorized through {@see \App\Policies\LibraryRenewalPolicy}
 * (`library_renewals.view`). The CSV carries the accession, title, member,
 * previous / new due dates, the renewal date and the renewing user — the
 * columns the listing shows. Renewals are append-only history: an export never
 * extends a due date.
 */
class LibraryRenewalBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return LibraryRenewal::class;
    }

    public function requiredPermission(): ?string
    {
        return 'library_renewals.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'library-renewals.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'renewal' : 'renewals';
    }
}
