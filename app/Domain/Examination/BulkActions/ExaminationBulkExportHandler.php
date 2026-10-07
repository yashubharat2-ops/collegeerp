<?php

namespace App\Domain\Examination\BulkActions;

use App\Models\Examination;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected examinations.
 *
 * Ids are re-queried inside the active college and each examination is
 * re-authorized through {@see \App\Policies\ExaminationPolicy}
 * (`examinations.view`). The CSV is streamed by `ExaminationController::export`,
 * which re-applies the same search / year / term / status filters as the list.
 *
 * Read-only: examinations are created, edited and deleted only through their
 * existing single-record, validated and audited routes.
 */
class ExaminationBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return Examination::class;
    }

    public function requiredPermission(): ?string
    {
        return 'examinations.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'examinations.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'examination' : 'examinations';
    }
}
