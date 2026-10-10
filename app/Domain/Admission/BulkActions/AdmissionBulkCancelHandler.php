<?php

namespace App\Domain\Admission\BulkActions;

use App\Domain\Admission\Services\AdmissionService;
use App\Models\Admission;
use App\Models\College;
use App\Models\User;
use App\Support\BulkAction\BulkActionHandler;
use App\Support\BulkAction\BulkActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cancel selected admissions through {@see AdmissionService::cancelAdmission()}.
 *
 * Already-cancelled rows are no-ops. An admission whose application already has
 * a live student is BLOCKED: it is skipped and reported, never cancelled and
 * never touched (the student is not cancelled or deleted). The remaining rows
 * are cancelled in one transaction, so a later failure never leaves a partial
 * cancel set.
 */
class AdmissionBulkCancelHandler extends BulkActionHandler
{
    public function __construct(private readonly AdmissionService $admissions) {}

    public function modelClass(): string
    {
        return Admission::class;
    }

    public function requiredPermission(): ?string
    {
        return 'admissions.update';
    }

    public function policyAbility(): ?string
    {
        return 'update';
    }

    public function handle(Collection $records, User $user, College $college, array $parameters = []): BulkActionResult
    {
        $blocked = 0;

        try {
            $affected = DB::transaction(function () use ($records, &$blocked): int {
                $count = 0;

                // Deterministic lock order (ascending id) across overlapping bulk requests.
                foreach ($records->sortBy('id') as $admission) {
                    if ($admission->status === 'cancelled') {
                        continue;
                    }

                    // Each row runs in a savepoint so a block on one row is a clean
                    // skip; the service re-checks under the application lock.
                    try {
                        DB::transaction(fn () => $this->admissions->cancelAdmission($admission));
                    } catch (ValidationException) {
                        $blocked++;

                        continue;
                    }

                    $count++;
                }

                return $count;
            });
        } catch (Throwable $e) {
            return BulkActionResult::failed('The admissions could not be cancelled. No records were changed.');
        }

        if ($affected === 0 && $blocked > 0) {
            return BulkActionResult::failed(
                $blocked === 1
                    ? 'The selected admission cannot be cancelled because a student record already exists for its application. Nothing was changed.'
                    : 'None of the selected admissions can be cancelled: each has a student record already. Nothing was changed.'
            );
        }

        $message = $affected === 1
            ? '1 admission cancelled.'
            : $affected.' admissions cancelled.';

        if ($blocked > 0) {
            $message .= ' '.$blocked.' '.($blocked === 1 ? 'was' : 'were').' not cancelled because a student record already exists for its application.';
        }

        return BulkActionResult::success($message, $affected);
    }
}
