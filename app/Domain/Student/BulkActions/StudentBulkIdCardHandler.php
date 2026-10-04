<?php

namespace App\Domain\Student\BulkActions;

use App\Models\College;
use App\Models\Student;
use App\Models\User;
use App\Support\BulkAction\BulkActionHandler;
use App\Support\BulkAction\BulkActionResult;
use Illuminate\Database\Eloquent\Collection;

/**
 * Bulk action: generate ID cards for the selected students.
 *
 * Nothing is persisted by an ID card, so "generating" a batch means exactly what
 * it means for a single card: rendering it from the live Student +
 * StudentEnrollment record. The reusable bulk infrastructure has already
 * re-queried the ids inside the college scope and dropped every student the user
 * may not view; this handler turns that authorized set into a printable batch
 * URL, and `StudentIdCardController::batch` re-checks every student, renders the
 * cards and writes one audit entry per card (`student_id_card.generated`, the
 * same action name the single-card screen uses).
 *
 * Permissions: `student_id_cards.generate` for the action and per-record
 * `students.view` through the Student policy.
 */
class StudentBulkIdCardHandler extends BulkActionHandler
{
    public function modelClass(): string
    {
        return Student::class;
    }

    public function requiredPermission(): ?string
    {
        return 'student_id_cards.generate';
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
            'ID cards prepared for '.$count.' '.(($count === 1) ? 'student' : 'students').'.',
            $count,
            [
                'redirect' => route('student-id-cards.batch', ['ids' => $ids]),
                'ids' => $ids,
            ]
        );
    }
}
