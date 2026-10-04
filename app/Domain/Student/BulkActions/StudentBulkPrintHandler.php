<?php

namespace App\Domain\Student\BulkActions;

/**
 * Bulk action: open the A4 student report for the selected students with the
 * browser's native print dialog already up.
 *
 * See StudentBulkReportHandler for the security model (server-side re-query
 * inside the college scope, per-record Student policy, ids built server-side).
 */
class StudentBulkPrintHandler extends StudentBulkReportHandler
{
    protected function routeName(): string
    {
        return 'students.export.print';
    }

    protected function label(): string
    {
        return 'Print view';
    }
}
