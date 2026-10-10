<?php

namespace App\Domain\Admission\BulkActions;

use App\Models\AdmissionApplication;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk CSV export of selected admission application records.
 *
 * Ids are re-queried inside the college scope by the framework, and the export
 * endpoint re-queries them again under the same scope and the `admission_applications.view` view
 * permission. Read-only: this handler never mutates a record.
 */
class AdmissionApplicationBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return AdmissionApplication::class;
    }

    public function requiredPermission(): ?string
    {
        return 'admission_applications.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'admission-applications.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'application' : 'applications';
    }
}
