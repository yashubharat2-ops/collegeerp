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
use Throwable;

/**
 * Cancel selected admissions through {@see AdmissionService::cancelAdmission()}.
 *
 * Already-cancelled rows are no-ops. The mutation runs in one transaction so a
 * later failure never leaves a partial cancel set.
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
        try {
            $affected = DB::transaction(function () use ($records): int {
                $count = 0;

                foreach ($records as $admission) {
                    if ($admission->status === 'cancelled') {
                        continue;
                    }

                    $this->admissions->cancelAdmission($admission);
                    $count++;
                }

                return $count;
            });
        } catch (Throwable $e) {
            return BulkActionResult::failed('The admissions could not be cancelled. No records were changed.');
        }

        return BulkActionResult::success(
            $affected === 1
                ? '1 admission cancelled.'
                : $affected.' admissions cancelled.',
            $affected
        );
    }
}
