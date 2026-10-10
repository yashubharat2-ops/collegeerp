<?php

namespace App\Domain\Inventory\BulkActions;

use App\Models\InventoryAssignment;
use App\Support\BulkAction\BulkExportHandler;
use Illuminate\Database\Eloquent\Model;

/**
 * Bulk export of the assets currently out (Inventory — Asset Return).
 *
 * The Asset Return listing shows ACTIVE assignment rows only, so this handler
 * accepts exactly those records: a returned assignment can never ride along in
 * an asset-return export. Each ticked row is re-queried inside the active
 * college and gated by the module permission
 * (`inventory_asset_returns.view`) — the InventoryAssignmentPolicy answers the
 * returns family with `viewReturns`, which is a listing-level ability, so no
 * per-record policy gate applies here. The CSV carries the asset, serial,
 * assignee, purpose and assigned-on date — the columns the listing shows. An
 * export never records a return.
 */
class InventoryAssetReturnBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return InventoryAssignment::class;
    }

    public function requiredPermission(): ?string
    {
        return 'inventory_asset_returns.view';
    }

    protected function acceptsRecord(Model $record): bool
    {
        return $record instanceof InventoryAssignment && $record->isActive();
    }

    protected function exportRouteName(): string
    {
        return 'inventory-asset-returns.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'asset return' : 'asset returns';
    }
}
