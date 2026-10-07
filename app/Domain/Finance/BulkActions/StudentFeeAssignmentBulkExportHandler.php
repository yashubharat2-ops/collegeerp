<?php

namespace App\Domain\Finance\BulkActions;

use App\Models\StudentFeeAssignment;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected student fee assignments (Finance — Student Fee Assignments).
 *
 * Each ticked assignment is re-queried inside the active college and re-authorized through
 * {@see \App\Policies\StudentFeeAssignmentPolicy} (`student_fee_assignments.view`). The
 * per-row money columns come from the existing FeeDuesService ledger, which the export
 * endpoint re-computes for exactly the authorized ids.
 * Read-only by construction: the export endpoint re-queries the ticked ids inside the active college and the CSV carries the same columns the listing shows — no money value is recalculated here, and no payment, receipt, refund, concession, assignment or ledger row is written.
 */
class StudentFeeAssignmentBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return StudentFeeAssignment::class;
    }

    public function requiredPermission(): ?string
    {
        return 'student_fee_assignments.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'student-fee-assignments.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'fee assignment' : 'fee assignments';
    }
}
