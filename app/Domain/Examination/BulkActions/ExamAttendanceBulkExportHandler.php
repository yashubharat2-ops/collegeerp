<?php

namespace App\Domain\Examination\BulkActions;

use App\Models\ExamAttendance;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected exam attendance records.
 *
 * Ids are re-queried inside the active college and each record is re-authorized
 * through {@see \App\Policies\ExamAttendancePolicy} (`exam_attendance.view`).
 *
 * Export only: attendance is marked through the existing exam-schedule marking
 * board (and its bulk save), which resolves eligibility server-side. A bulk
 * status flip here would bypass that eligibility check, so it is not offered.
 */
class ExamAttendanceBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return ExamAttendance::class;
    }

    public function requiredPermission(): ?string
    {
        return 'exam_attendance.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'exam-attendance.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'exam attendance record' : 'exam attendance records';
    }
}
