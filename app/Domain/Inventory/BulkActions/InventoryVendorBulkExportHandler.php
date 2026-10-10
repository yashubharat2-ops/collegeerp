<?php

namespace App\Domain\Inventory\BulkActions;

use App\Models\InventoryVendor;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected vendors (Inventory — Vendors).
 *
 * Each ticked vendor is re-queried inside the active college and
 * re-authorized through {@see \App\Policies\InventoryVendorPolicy}
 * (`inventory_vendors.view`). The CSV carries the name, code, contact person,
 * phone, e-mail, address, GST number and status — the supplier master the
 * listing shows. The GST number is the college's own tax identifier for the
 * vendor, not a personal government identity number. An export never edits or
 * deactivates a vendor.
 */
class InventoryVendorBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return InventoryVendor::class;
    }

    public function requiredPermission(): ?string
    {
        return 'inventory_vendors.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'inventory-vendors.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'vendor' : 'vendors';
    }
}
