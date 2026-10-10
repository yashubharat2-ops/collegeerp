<?php

namespace App\Domain\Admission\BulkActions;

use App\Models\AdmissionApplicant;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk CSV export of selected applicant records.
 *
 * Ids are re-queried inside the college scope by the framework, and the export
 * endpoint re-queries them again under the same scope and the `admission_applicants.view` view
 * permission. Read-only: this handler never mutates a record.
 */
class AdmissionApplicantBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return AdmissionApplicant::class;
    }

    public function requiredPermission(): ?string
    {
        return 'admission_applicants.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'admission-applicants.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'applicant' : 'applicants';
    }
}
