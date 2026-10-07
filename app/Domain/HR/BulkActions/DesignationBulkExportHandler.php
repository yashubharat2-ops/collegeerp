<?php

namespace App\Domain\HR\BulkActions;

use App\Models\Designation;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected designations (HR — Designations).
 *
 * Each ticked designation is re-queried inside the active college and re-authorized through
 * {@see \App\Policies\DesignationPolicy} (`designations.view`). The employee count in the
 * CSV is the same live count the listing shows.
 * Read-only by construction: the export endpoint re-queries the ticked ids inside the active college and the CSV carries the same columns the listing shows. Nothing is written back — no payroll, salary, attendance, leave-approval or employee-status change exists on this route.
 */
class DesignationBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return Designation::class;
    }

    public function requiredPermission(): ?string
    {
        return 'designations.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'designations.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'designation' : 'designations';
    }
}
