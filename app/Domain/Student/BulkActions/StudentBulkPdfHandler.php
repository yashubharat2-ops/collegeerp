<?php

namespace App\Domain\Student\BulkActions;

/**
 * Bulk action: open the A4 student report for the selected students, ready to be
 * saved as a PDF from the browser's print dialog.
 *
 * See StudentBulkReportHandler for the security model (server-side re-query
 * inside the college scope, per-record Student policy, ids built server-side).
 */
class StudentBulkPdfHandler extends StudentBulkReportHandler
{
    protected function routeName(): string
    {
        return 'students.export.pdf';
    }

    protected function label(): string
    {
        return 'PDF report';
    }
}
