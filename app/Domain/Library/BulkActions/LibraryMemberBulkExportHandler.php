<?php

namespace App\Domain\Library\BulkActions;

use App\Models\LibraryMember;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected library memberships (Library — Library Members).
 *
 * Each ticked membership is re-queried inside the active college and
 * re-authorized through {@see \App\Policies\LibraryMemberPolicy}
 * (`library_members.view`). The CSV carries the member code, the student and
 * enrollment it hangs off, membership / expiry dates and the status — the
 * same columns the listing shows. No government identity number is stored on
 * the membership, and none is exported. Suspending or deleting a membership
 * stays a single-record workflow.
 */
class LibraryMemberBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return LibraryMember::class;
    }

    public function requiredPermission(): ?string
    {
        return 'library_members.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'library-members.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'library member' : 'library members';
    }
}
