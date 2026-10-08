<?php

namespace App\Domain\Academic\BulkActions;

use App\Models\AcademicTimetable;
use App\Support\BulkAction\BulkExportHandler;
use Illuminate\Database\Eloquent\Model;

/**
 * Bulk export of selected faculty workload lines.
 *
 * Workload is DERIVED — no workload table exists, the screen groups the active
 * timetable entries by (faculty, subject, section, academic year, term). A
 * workload line therefore has no primary key of its own, and each line on the
 * screen carries the id of its group's representative active timetable entry
 * (`rep_id`, the lowest id of the group — see
 * {@see \App\Domain\Academic\Services\FacultyWorkloadService}).
 *
 * Selecting a line is thus a request to export THAT timetable group, and the
 * request is re-resolved exactly like every other bulk export:
 *
 *  - the representative ids are re-queried inside the active college;
 *  - only ACTIVE timetable entries count as workload (a soft-deleted or
 *    inactive entry is skipped, and reported as skipped);
 *  - the export endpoint re-derives each group from its representative row
 *    server-side, so a hand-edited id can never change what a line contains.
 *
 * Authorization is the workload screen's own permission
 * (`academic_workload.view`); the timetable policy is deliberately not required
 * on top of it, otherwise the workbook would be readable and its export not.
 */
class AcademicWorkloadBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return AcademicTimetable::class;
    }

    public function requiredPermission(): ?string
    {
        return 'academic_workload.view';
    }

    protected function acceptsRecord(Model $record): bool
    {
        return $record instanceof AcademicTimetable
            && $record->status === 'active';
    }

    protected function exportRouteName(): string
    {
        return 'academic-workload.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'workload line' : 'workload lines';
    }
}
