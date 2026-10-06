<?php

namespace App\Domain\Student\BulkActions;

use App\Domain\Student\Services\StudentService;
use App\Models\College;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Support\BulkAction\BulkActionHandler;
use App\Support\BulkAction\BulkActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Bulk status change for enrollments, applied through
 * {@see StudentService::updateEnrollment()} so duplicate-active protection,
 * tenant re-resolution and audit stay on the existing write path.
 *
 * The target status is a validated parameter (never a free-form column write).
 * The whole selection is applied in one transaction: a duplicate-active clash
 * or any other failure rolls every change back.
 */
class EnrollmentBulkStatusHandler extends BulkActionHandler
{
    public function __construct(private readonly StudentService $students) {}

    public function modelClass(): string
    {
        return StudentEnrollment::class;
    }

    public function requiredPermission(): ?string
    {
        return 'student_enrollments.update';
    }

    public function policyAbility(): ?string
    {
        return 'update';
    }

    public function handle(Collection $records, User $user, College $college, array $parameters = []): BulkActionResult
    {
        $status = $parameters['status'] ?? null;

        if (! is_string($status) || ! in_array($status, StudentEnrollment::STATUSES, true)) {
            return BulkActionResult::failed('Choose a valid enrollment status.');
        }

        try {
            $affected = DB::transaction(function () use ($records, $college, $status): int {
                $count = 0;

                foreach ($records as $enrollment) {
                    if ($enrollment->status === $status) {
                        continue;
                    }

                    $this->students->updateEnrollment($enrollment, [
                        'status' => $status,
                    ], (int) $college->id);
                    $count++;
                }

                return $count;
            });
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first()
                ?: 'The enrollments could not be updated. No records were changed.';

            return BulkActionResult::failed($message);
        } catch (Throwable $e) {
            return BulkActionResult::failed('The enrollments could not be updated. No records were changed.');
        }

        return BulkActionResult::success(
            $affected === 1
                ? '1 enrollment updated.'
                : $affected.' enrollments updated.',
            $affected
        );
    }
}
