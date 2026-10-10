<?php

namespace App\Domain\Inventory\BulkActions;

use App\Models\InventoryIssue;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected item issues / allocations (Inventory — Item Issue /
 * Allocation).
 *
 * Each ticked issue is re-queried inside the active college and re-authorized
 * through {@see \App\Policies\InventoryIssuePolicy}
 * (`inventory_issues.view`). The CSV carries the date, issue number, item,
 * quantity, recipient, purpose / reference and the recording user — the
 * columns the listing shows. Issues are append-only: an export never issues
 * stock or reverses an issue.
 */
class InventoryIssueBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return InventoryIssue::class;
    }

    public function requiredPermission(): ?string
    {
        return 'inventory_issues.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'inventory-issues.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'issue' : 'issues';
    }
}
