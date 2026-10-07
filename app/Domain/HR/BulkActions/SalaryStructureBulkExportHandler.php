<?php

namespace App\Domain\HR\BulkActions;

use App\Models\SalaryStructure;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected salary structures (HR — Staff Salary / Payroll).
 *
 * Each ticked structure is re-queried inside the active college and re-authorized through
 * {@see \App\Policies\SalaryStructurePolicy} (`salary_structures.view`). The CSV carries the
 * definition (name, code, effective date, component count, status) — never an employee’s pay
 * figures.
 * Read-only by construction: the export endpoint re-queries the ticked ids inside the active college and the CSV carries the same columns the listing shows. Nothing is written back — no payroll, salary, attendance, leave-approval or employee-status change exists on this route.
 */
class SalaryStructureBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return SalaryStructure::class;
    }

    public function requiredPermission(): ?string
    {
        return 'salary_structures.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'salary-structures.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'salary structure' : 'salary structures';
    }
}
