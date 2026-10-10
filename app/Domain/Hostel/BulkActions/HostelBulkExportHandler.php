<?php

namespace App\Domain\Hostel\BulkActions;

use App\Models\Hostel;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected hostel masters (Hostel — Hostels).
 *
 * Each ticked hostel is re-queried inside the active college and
 * re-authorized through {@see \App\Policies\HostelPolicy}
 * (`hostels.view`). The CSV carries the name, code, type, gender, address and
 * status plus the derived building / room / bed counts — the columns the
 * listing shows. An export never creates, renames or deactivates a hostel.
 */
class HostelBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return Hostel::class;
    }

    public function requiredPermission(): ?string
    {
        return 'hostels.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'hostels.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'hostel' : 'hostels';
    }
}
