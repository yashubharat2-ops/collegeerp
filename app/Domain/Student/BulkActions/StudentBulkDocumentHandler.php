<?php

namespace App\Domain\Student\BulkActions;

use App\Models\College;
use App\Models\Student;
use App\Models\User;
use App\Support\BulkAction\BulkActionHandler;
use App\Support\BulkAction\BulkActionResult;
use Illuminate\Database\Eloquent\Collection;

/**
 * Bulk action: build a document pack for the selected students.
 *
 * The pack is a consolidated, printable summary of the documents ALREADY on file
 * for those students (see StudentDocumentPackService): their verification status
 * plus any required document type that is still missing. It creates no records,
 * copies no files and stores no generated document — a second document checklist
 * would be one more thing to keep in sync with the documents module.
 *
 * The reusable bulk infrastructure has already re-queried the ids inside the
 * college scope and dropped every student the user may not view;
 * `StudentDocumentController::batch` re-checks each student and re-queries the
 * documents through the tenant-scoped StudentDocument query before rendering.
 *
 * Permissions: `student_documents.view` for the action and per-record
 * `students.view` through the Student policy.
 */
class StudentBulkDocumentHandler extends BulkActionHandler
{
    public function modelClass(): string
    {
        return Student::class;
    }

    public function requiredPermission(): ?string
    {
        return 'student_documents.view';
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
            'Document pack prepared for '.$count.' '.(($count === 1) ? 'student' : 'students').'.',
            $count,
            [
                'redirect' => route('student-documents.batch', ['ids' => $ids]),
                'ids' => $ids,
            ]
        );
    }
}
