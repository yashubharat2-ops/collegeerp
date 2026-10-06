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

/**
 * Mark selected admissions completed via {@see AdmissionService::updateAdmission()}.
 *
 * Cancelled admissions cannot be completed; if any selected (authorized) row is
 * cancelled the whole batch is refused and nothing is written.
 */
class AdmissionBulkCompleteHandler extends BulkActionHandler
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
        foreach ($records as $admission) {
            if ($admission->status === 'cancelled') {
                return BulkActionResult::failed(
                    'A cancelled admission cannot be completed. No admissions were changed.'
                );
            }
        }

        $affected = DB::transaction(function () use ($records): int {
            $count = 0;

            foreach ($records as $admission) {
                if ($admission->status === 'completed') {
                    continue;
                }

                $this->admissions->updateAdmission($admission, [
                    'status' => 'completed',
                ]);
                $count++;
            }

            return $count;
        });

        return BulkActionResult::success(
            $affected === 1
                ? '1 admission marked completed.'
                : $affected.' admissions marked completed.',
            $affected
        );
    }
}
