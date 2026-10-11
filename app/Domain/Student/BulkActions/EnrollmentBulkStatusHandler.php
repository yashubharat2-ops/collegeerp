<?php

namespace App\Domain\Student\BulkActions;

use App\Domain\Student\Services\StudentService;
use App\Models\College;
use App\Models\Student;
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
 * {@see StudentService::updateEnrollment()} so the terminal-state guard,
 * centralized tenant/tuple validation and audit stay on the existing write
 * path. The target status is validated (never a free-form column write).
 *
 * The whole selection is applied in one transaction: any validation or write
 * failure rolls every change back.
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

        // Route every request through the same lock order, even when the caller's
        // collection came from an unordered query or stale request-time models.
        $ordered = $records->sortBy(['student_id', 'id']);

        try {
            $affected = DB::transaction(function () use ($ordered, $college, $status): int {
                $count = 0;
                $collegeId = (int) $college->id;

                foreach ($ordered as $selected) {
                    // Re-read under the transaction: never make a no-op decision
                    // from a stale status, and lock Student before Enrollment just
                    // as StudentService does for individual changes.
                    $student = Student::withoutGlobalScopes()
                        ->where('college_id', $collegeId)
                        ->whereKey((int) $selected->student_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $student) {
                        throw ValidationException::withMessages([
                            'bulk' => 'A selected enrollment changed or is no longer available. No records were changed.',
                        ]);
                    }

                    $enrollment = StudentEnrollment::withoutGlobalScopes()
                        ->where('college_id', $collegeId)
                        ->where('student_id', (int) $selected->student_id)
                        ->whereNull('deleted_at')
                        ->whereKey((int) $selected->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $enrollment) {
                        throw ValidationException::withMessages([
                            'bulk' => 'A selected enrollment changed or is no longer available. No records were changed.',
                        ]);
                    }

                    if ($enrollment->status === $status) {
                        continue;
                    }

                    $this->students->updateEnrollment($enrollment, [
                        'status' => $status,
                    ], $collegeId);
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
