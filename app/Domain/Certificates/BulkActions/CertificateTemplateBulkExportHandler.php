<?php

namespace App\Domain\Certificates\BulkActions;

use App\Models\CertificateTemplate;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected certificate templates (Certificate Management —
 * Certificate Templates).
 *
 * Each ticked template is re-queried inside the active college and gated by
 * the template-management permission (`certificate_templates.manage`) —
 * CertificateTemplate has no policy class; the controller's permission check
 * is the module's own convention. The CSV carries the template name, its
 * certificate type and the plain-text body. Adding or revising a template
 * stays on its own screen.
 */
class CertificateTemplateBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return CertificateTemplate::class;
    }

    public function requiredPermission(): ?string
    {
        return 'certificate_templates.manage';
    }

    protected function exportRouteName(): string
    {
        return 'certificates.templates.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'template' : 'templates';
    }
}
