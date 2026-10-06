<?php

namespace App\Domain\Student\BulkActions;

use App\Models\College;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Support\BulkAction\BulkActionHandler;
use App\Support\BulkAction\BulkActionResult;
use Illuminate\Database\Eloquent\Collection;

/**
 * Bulk export of selected enrollments. Ids are re-queried tenant-scoped before
 * the CSV endpoint streams them. Student identity numbers are never included.
 */
class EnrollmentBulkExportHandler extends BulkActionHandler
{
    public function modelClass(): string
    {
        return StudentEnrollment::class;
    }

    public function requiredPermission(): ?string
    {
        return 'student_enrollments.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    public function handle(Collection $records, User $user, College $college, array $parameters = []): BulkActionResult
    {
        $ids = $records->map(fn (StudentEnrollment $enrollment) => (int) $enrollment->getKey())->values()->all();
        $count = count($ids);

        return BulkActionResult::success(
            'Export prepared for '.$count.' '.(($count === 1) ? 'enrollment' : 'enrollments').'.',
            $count,
            [
                'redirect' => route('student-enrollments.export', ['ids' => $ids]),
                'ids' => $ids,
            ]
        );
    }
}
