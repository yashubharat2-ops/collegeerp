<?php

namespace App\Domain\Transport\BulkActions;

use App\Models\StudentTransportAssignment;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected student transport assignments (Transport — Student
 * Transport Assignment).
 *
 * Each ticked assignment is re-queried inside the active college and
 * re-authorized through {@see \App\Policies\StudentTransportAssignmentPolicy}
 * (`student_transport_assignments.view`). The CSV carries the student,
 * enrollment, academic year, route, stop, period and status — the columns the
 * listing shows. No assignment is created, completed or cancelled by an
 * export.
 */
class StudentTransportAssignmentBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return StudentTransportAssignment::class;
    }

    public function requiredPermission(): ?string
    {
        return 'student_transport_assignments.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'transport-assignments.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'transport assignment' : 'transport assignments';
    }
}
