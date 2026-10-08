<?php

namespace App\Domain\Certificates\BulkActions;

use App\Models\Certificate;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected certificate records (Certificate Management — the
 * register screen).
 *
 * One register screen covers every certificate type (Transfer, Bonafide,
 * Character, Course Completion, Migration, Provisional, Custom) at every
 * stage (requests, generation, issuance, verification), so one action serves
 * them all: the stage is a view filter, while the ticked ids identify the
 * records. Each id is re-queried inside the active college and gated by the
 * module permission (`certificates.view`) — Certificate has no policy class,
 * the controller's permission check is the module's own convention. The CSV
 * carries the request id, number, type, student, enrollment, status and the
 * requested / generated / issued / verified timestamps. The template
 * snapshot, the data snapshot and the free-text purpose are never exported.
 * Requesting, generating, issuing or verifying a certificate stays a
 * single-record workflow.
 */
class CertificateBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return Certificate::class;
    }

    public function requiredPermission(): ?string
    {
        return 'certificates.view';
    }

    protected function exportRouteName(): string
    {
        return 'certificates.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'certificate' : 'certificates';
    }
}
