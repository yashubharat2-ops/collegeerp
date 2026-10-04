<?php

namespace App\Domain\Student\BulkActions;

use App\Models\College;
use App\Models\Student;
use App\Models\User;
use App\Support\BulkAction\BulkActionHandler;
use App\Support\BulkAction\BulkActionResult;
use Illuminate\Database\Eloquent\Collection;

/**
 * Bulk action: export the selected students.
 *
 * The handler never exports the client's list. `BulkActionHandler::execute()`
 * has already re-queried the ids inside the college scope and dropped every
 * record the user may not view, so:
 *
 *  - a foreign college's id, a soft-deleted student and an id the user cannot
 *    view are all skipped (and reported as skipped), and
 *  - the download URL handed back below carries ONLY those surviving ids.
 *
 * The CSV itself is streamed by `StudentController::export`, which re-resolves
 * each id through the tenant-scoped Student query again and applies the very
 * same filter pipeline as the list (see StudentListService), so a hand-edited
 * URL can never widen the export. Permissions: `students.export` for the
 * action and per-record `students.view` through the Student policy.
 */
class StudentBulkExportHandler extends BulkActionHandler
{
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
            'Export prepared for '.$count.' '.(($count === 1) ? 'student' : 'students').'.',
            $count,
            [
                // Only authorized ids travel in the URL; the export endpoint
                // re-queries them and re-applies the list filters.
                'redirect' => route('students.export', ['ids' => $ids]),
                'ids' => $ids,
            ]
        );
    }
}
