<?php

namespace App\Domain\HR\BulkActions;

use App\Models\SalaryComponent;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected salary components (HR — Staff Salary / Payroll).
 *
 * Each ticked component is re-queried inside the active college and re-authorized through
 * {@see \App\Policies\SalaryComponentPolicy} (`salary_components.view`). Those values are
 * configuration read live from the structure, so the selection is export only.
 * Read-only by construction: the export endpoint re-queries the ticked ids inside the active college and the CSV carries the same columns the listing shows. Nothing is written back — no payroll, salary, attendance, leave-approval or employee-status change exists on this route.
 */
class SalaryComponentBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return SalaryComponent::class;
    }

    public function requiredPermission(): ?string
    {
        return 'salary_components.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'salary-components.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'salary component' : 'salary components';
    }
}
