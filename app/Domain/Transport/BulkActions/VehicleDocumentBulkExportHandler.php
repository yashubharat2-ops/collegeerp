<?php

namespace App\Domain\Transport\BulkActions;

use App\Models\VehicleDocument;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected vehicle-document metadata (Transport — Vehicle
 * Documents).
 *
 * Each ticked document is re-queried inside the active college and
 * re-authorized through {@see \App\Policies\VehicleDocumentPolicy}
 * (`vehicle_documents.view`). Vehicle-document exports carry METADATA ONLY:
 * the vehicle, document type, document number, issue / expiry dates, the
 * validity status, the original filename and its size. The private server path
 * (`file_path`) is never queried into the CSV and the file itself is still
 * served exclusively by the authorized download route. Deleting a document
 * stays a single-record workflow.
 */
class VehicleDocumentBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return VehicleDocument::class;
    }

    public function requiredPermission(): ?string
    {
        return 'vehicle_documents.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'vehicle-documents.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'vehicle document' : 'vehicle documents';
    }
}
