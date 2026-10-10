<?php

namespace App\Domain\Library\BulkActions;

use App\Models\Book;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected book masters (Library — Books).
 *
 * Books are the bibliographic master: each ticked id is re-queried inside the
 * active college and re-authorized through {@see \App\Policies\BookPolicy}
 * (`books.view`). The CSV carries the columns the listing shows — title, code,
 * ISBN, category, authors, publisher, year and status — and never a private
 * path or a circulation field. Read-only by construction: the export endpoint
 * re-queries the ticked ids inside the active college again. No bulk issue,
 * return or deletion exists on this route.
 */
class BookBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return Book::class;
    }

    public function requiredPermission(): ?string
    {
        return 'books.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'books.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'book' : 'books';
    }
}
