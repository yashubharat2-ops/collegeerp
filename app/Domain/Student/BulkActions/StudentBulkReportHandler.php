<?php

namespace App\Domain\Student\BulkActions;

use App\Models\College;
use App\Models\Student;
use App\Models\User;
use App\Support\BulkAction\BulkActionHandler;
use App\Support\BulkAction\BulkActionResult;
use Illuminate\Database\Eloquent\Collection;

/**
 * Base for the bulk actions that open the printable A4 report for a selection —
 * the Export menu's "PDF" and "Print" options.
 *
 * These are the same operation as StudentBulkExportHandler (a follow-up
 * destination built from the ids the user is allowed to export), differing only
 * in the target route, so the security model is stated once here and inherited:
 *
 *  - the handler never exports the client's list: BulkActionHandler::execute()
 *    has already re-queried the ids inside the college scope and dropped every
 *    record the user may not view (each skip is reported), and
 *  - the URL handed back carries ONLY those surviving ids. The report endpoint
 *    re-resolves them through the tenant-scoped Student query, re-checks
 *    `students.export` and re-applies the per-record Student policy, so a
 *    hand-edited URL can never widen the report.
 *
 * No new permission is involved: the report is the export, in a printable form.
 */
abstract class StudentBulkReportHandler extends BulkActionHandler
{
    /**
     * Route the browser is sent to, with only the authorized ids.
     */
    abstract protected function routeName(): string;

    /**
     * Human label for the flash message, e.g. "PDF report" / "Print view".
     */
    abstract protected function label(): string;

    public function modelClass(): string
    {
        return Student::class;
    }

    public function requiredPermission(): ?string
    {
        return 'students.export';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    public function handle(Collection $records, User $user, College $college, array $parameters = []): BulkActionResult
    {
        $ids = $records->map(fn (Student $student) => (int) $student->getKey())->values()->all();
        $count = count($ids);

        return BulkActionResult::success(
            $this->label().' prepared for '.$count.' '.(($count === 1) ? 'student' : 'students').'.',
            $count,
            [
                // Only authorized ids travel in the URL; the report endpoint
                // re-queries them and re-applies the list filters.
                'redirect' => route($this->routeName(), ['ids' => $ids]),
                'ids' => $ids,
            ]
        );
    }
}
