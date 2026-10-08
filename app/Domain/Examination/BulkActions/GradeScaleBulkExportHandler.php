<?php

namespace App\Domain\Examination\BulkActions;

use App\Models\GradeScale;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected grade / pass-fail scales.
 *
 * Ids are re-queried inside the active college and each scale is re-authorized
 * through {@see \App\Policies\GradeScalePolicy} (`grade_scales.view`). The CSV
 * carries the scale definition plus its configured bands — no student data at
 * all.
 *
 * Export only: bands are contiguous, non-overlapping ranges validated by
 * GradeScaleService, so they are edited one scale at a time, never in bulk.
 */
class GradeScaleBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return GradeScale::class;
    }

    public function requiredPermission(): ?string
    {
        return 'grade_scales.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'grade-scales.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'grade scale' : 'grade scales';
    }
}
