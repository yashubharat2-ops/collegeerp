<?php

namespace App\Domain\Transport\BulkActions;

use App\Models\TransportDriver;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected drivers (Transport — Drivers).
 *
 * Each ticked driver is re-queried inside the active college and
 * re-authorized through {@see \App\Policies\TransportDriverPolicy}
 * (`transport_drivers.view`). The CSV carries the linked staff member, license
 * TYPE, license expiry, joining date and status — the columns the listing
 * shows. The license NUMBER is a government identity document number and is
 * deliberately never exported. No driver is activated, deactivated or
 * re-assigned by an export.
 */
class TransportDriverBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return TransportDriver::class;
    }

    public function requiredPermission(): ?string
    {
        return 'transport_drivers.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'transport-drivers.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'driver' : 'drivers';
    }
}
