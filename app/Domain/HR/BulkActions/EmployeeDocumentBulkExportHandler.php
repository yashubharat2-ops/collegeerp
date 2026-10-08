<?php

namespace App\Domain\HR\BulkActions;

use App\Models\EmployeeDocument;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected employee-document metadata (HR — Employee Documents).
 *
 * Each ticked document is re-queried inside the active college and re-authorized through
 * {@see \App\Policies\EmployeeDocumentPolicy} (`employee_documents.view`).
 * Employee-document exports carry metadata only: the document name, type, dates, the original filename and its size. The private server path and any stored file contents are never queried, never streamed and never written into the CSV.
 */
class EmployeeDocumentBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return EmployeeDocument::class;
    }

    public function requiredPermission(): ?string
    {
        return 'employee_documents.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'employee-documents.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'employee document' : 'employee documents';
    }
}
