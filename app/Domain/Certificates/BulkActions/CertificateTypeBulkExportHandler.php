<?php

namespace App\Domain\Certificates\BulkActions;

use App\Models\CertificateType;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected certificate types (Certificate Management —
 * Certificate Types).
 *
 * Each ticked type is re-queried inside the active college and gated by the
 * type-management permission (`certificate_types.manage`) — CertificateType
 * has no policy class; the controller's permission check is the module's own
 * convention. The CSV carries the name, code, description, template count and
 * origin. Managing a type stays behind its own screen.
 */
class CertificateTypeBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return CertificateType::class;
    }

    public function requiredPermission(): ?string
    {
        return 'certificate_types.manage';
    }

    protected function exportRouteName(): string
    {
        return 'certificates.types.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'certificate type' : 'certificate types';
    }
}
