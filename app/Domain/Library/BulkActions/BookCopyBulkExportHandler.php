<?php

namespace App\Domain\Library\BulkActions;

use App\Models\BookCopy;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected physical book copies (Library — Book Copies).
 *
 * Each ticked copy is re-queried inside the active college and re-authorized
 * through {@see \App\Policies\BookCopyPolicy} (`book_copies.view`). The CSV
 * carries the columns the listing shows — accession number, title, copy
 * number, barcode, location, condition and status. The copy's circulation
 * state is exported as stored; nothing is issued, returned or withdrawn by an
 * export.
 */
class BookCopyBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return BookCopy::class;
    }

    public function requiredPermission(): ?string
    {
        return 'book_copies.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'book-copies.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'book copy' : 'book copies';
    }
}
