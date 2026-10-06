<?php

namespace App\Domain\Admission\BulkActions;

use App\Models\Admission;
use App\Models\College;
use App\Models\User;
use App\Support\BulkAction\BulkActionHandler;
use App\Support\BulkAction\BulkActionResult;
use Illuminate\Database\Eloquent\Collection;

/**
 * Bulk export of selected admissions. Ids are re-queried inside the college
 * scope by {@see BulkActionHandler::execute()}; the CSV endpoint re-queries
 * them again. No identity numbers are exported.
 */
class AdmissionBulkExportHandler extends BulkActionHandler
{
    public function modelClass(): string
    {
        return Admission::class;
    }

    public function requiredPermission(): ?string
    {
        return 'admissions.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    public function handle(Collection $records, User $user, College $college, array $parameters = []): BulkActionResult
    {
        $ids = $records->map(fn (Admission $admission) => (int) $admission->getKey())->values()->all();
        $count = count($ids);

        return BulkActionResult::success(
            'Export prepared for '.$count.' '.(($count === 1) ? 'admission' : 'admissions').'.',
            $count,
            [
                'redirect' => route('admissions.export', ['ids' => $ids]),
                'ids' => $ids,
            ]
        );
    }
}
