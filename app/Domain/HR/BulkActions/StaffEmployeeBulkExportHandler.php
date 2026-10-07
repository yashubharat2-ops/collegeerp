<?php

namespace App\Domain\HR\BulkActions;

use App\Models\Faculty;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected staff / employees (HR — Staff / Employee).
 *
 * The HR screen lists the shared Platform Faculty/Staff records (one employee record per
 * college across Academic and HR), so each ticked id is re-queried inside the active college
 * and re-authorized through {@see \App\Policies\FacultyPolicy} (`faculties.view`). The CSV
 * carries the columns the listing shows — name, employee code, department, designation,
 * contact, employment type and status — and never a government id, bank or credential field.
 * Read-only by construction: the export endpoint re-queries the ticked ids inside the active college and the CSV carries the same columns the listing shows. Nothing is written back — no payroll, salary, attendance, leave-approval or employee-status change exists on this route.
 */
class StaffEmployeeBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return Faculty::class;
    }

    public function requiredPermission(): ?string
    {
        return 'faculties.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'employees.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'staff record' : 'staff records';
    }
}
