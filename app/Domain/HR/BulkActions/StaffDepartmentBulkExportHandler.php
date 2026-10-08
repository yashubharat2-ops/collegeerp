<?php

namespace App\Domain\HR\BulkActions;

use App\Models\Department;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected staff departments (HR — Staff Departments).
 *
 * Each ticked department is re-queried inside the active college and re-authorized through
 * {@see \App\Policies\DepartmentPolicy} (`departments.view`). Departments are the shared
 * Platform master used by both the Academic and the HR screens, so they stay export only.
 * Read-only by construction: the export endpoint re-queries the ticked ids inside the active college and the CSV carries the same columns the listing shows. Nothing is written back — no payroll, salary, attendance, leave-approval or employee-status change exists on this route.
 */
class StaffDepartmentBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return Department::class;
    }

    public function requiredPermission(): ?string
    {
        return 'departments.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'staff-departments.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'staff department' : 'staff departments';
    }
}
