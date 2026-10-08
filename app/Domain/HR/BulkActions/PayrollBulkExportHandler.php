<?php

namespace App\Domain\HR\BulkActions;

use App\Models\Payroll;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected payroll records (HR — Staff Salary / Payroll).
 *
 * Each ticked payroll is re-queried inside the active college and re-authorized through
 * {@see \App\Policies\PayrollPolicy} (`payrolls.view`). Payroll figures are produced by
 * PayrollService from the assigned structure; this screen never edits them, and running or
 * cancelling a payroll stays a single-record action, so the bulk selection is export only.
 * Read-only by construction: the export endpoint re-queries the ticked ids inside the active college and the CSV carries the same columns the listing shows. Nothing is written back — no payroll, salary, attendance, leave-approval or employee-status change exists on this route.
 */
class PayrollBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return Payroll::class;
    }

    public function requiredPermission(): ?string
    {
        return 'payrolls.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'payrolls.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'payroll record' : 'payroll records';
    }
}
