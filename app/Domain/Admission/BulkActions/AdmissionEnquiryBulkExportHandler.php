<?php

namespace App\Domain\Admission\BulkActions;

use App\Models\AdmissionEnquiry;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk CSV export of selected admission enquiry records.
 *
 * Ids are re-queried inside the college scope by the framework, and the export
 * endpoint re-queries them again under the same scope and the `admission_enquiries.view` view
 * permission. Read-only: this handler never mutates a record.
 */
class AdmissionEnquiryBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return AdmissionEnquiry::class;
    }

    public function requiredPermission(): ?string
    {
        return 'admission_enquiries.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'admission-enquiries.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'enquiry' : 'enquiries';
    }
}
