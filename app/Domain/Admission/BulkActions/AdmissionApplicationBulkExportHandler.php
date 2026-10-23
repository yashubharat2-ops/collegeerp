<?php

namespace App\Domain\Admission\BulkActions;

use App\Domain\Student\Filters\StudentFilter;
use App\Models\AdmissionApplication;
use App\Models\College;
use App\Models\User;
use App\Support\BulkAction\BulkExportHandler;
use Illuminate\Database\Eloquent\Collection;

/**
 * Bulk CSV export of selected admission application records.
 *
 * Ids are re-queried inside the college scope by the framework, and the export
 * endpoint re-queries them again under the same scope and the `admission_applications.view` view
 * permission. Read-only: this handler never mutates a record.
 */
class AdmissionApplicationBulkExportHandler extends BulkExportHandler
{
    public function __construct(private readonly StudentFilter $studentFilter) {}

    public function modelClass(): string
    {
        return AdmissionApplication::class;
    }

    public function requiredPermission(): ?string
    {
        return 'admission_applications.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'admission-applications.export';
    }

    protected function exportParameters(Collection $records, User $user, College $college, array $parameters): array
    {
        $studentFilters = $this->studentFilter->fromArray($parameters, user: $user);

        return [
            'search' => is_scalar($parameters['search'] ?? null) ? (string) $parameters['search'] : '',
            'status' => is_scalar($parameters['status'] ?? null) ? (string) $parameters['status'] : '',
            ...$this->studentFilter->requestParameters($studentFilters),
        ];
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'application' : 'applications';
    }
}
