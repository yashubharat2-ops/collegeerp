<?php

namespace App\Domain\Examination\BulkActions;

use App\Models\ExamSchedule;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected exam schedule entries.
 *
 * Ids are re-queried inside the active college and each schedule is
 * re-authorized through {@see \App\Policies\ExamSchedulePolicy}
 * (`exam_schedules.view`).
 *
 * Export only: seating, timing, marks distribution and cancellation are
 * per-record operations with their own validation, and a "bulk edit schedule"
 * would be able to move an exam silently — so none is offered.
 */
class ExamScheduleBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return ExamSchedule::class;
    }

    public function requiredPermission(): ?string
    {
        return 'exam_schedules.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'exam-schedules.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'exam schedule entry' : 'exam schedule entries';
    }
}
