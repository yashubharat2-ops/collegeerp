<?php

namespace App\Support\BulkAction;

use App\Models\College;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use RuntimeException;

abstract class BulkActionHandler
{
    /**
     * Return the Eloquent Model class this action operates on.
     *
     * @return class-string<Model>
     */
    abstract public function modelClass(): string;

    /**
     * Return the required permission slug, or null if handled entirely via policy.
     */
    public function requiredPermission(): ?string
    {
        return null;
    }

    /**
     * Return the policy ability name to authorize per record (e.g. 'delete', 'update', 'export'), or null.
     */
    public function policyAbility(): ?string
    {
        return null;
    }

    /**
     * Process the authorized records.
     *
     * @param Collection<int, Model> $records Scoped, authorized Eloquent records
     * @param User $user Authenticated user
     * @param College $college Active college context
     * @param array<string, mixed> $parameters Extra validated parameters
     * @return BulkActionResult
     */
    abstract public function handle(Collection $records, User $user, College $college, array $parameters = []): BulkActionResult;

    /**
     * Execute the bulk action on the given IDs within the tenant context.
     *
     * @param array<int, int|string> $ids Raw IDs from request
     * @param User $user Current user
     * @param College $college Current college
     * @param array<string, mixed> $parameters
     * @return BulkActionResult
     */
    public function execute(array $ids, User $user, College $college, array $parameters = []): BulkActionResult
    {
        // 1. Authorize module permission if defined
        if ($perm = $this->requiredPermission()) {
            if (! $user->hasPermission($perm, $college->id)) {
                return BulkActionResult::forbidden("Unauthorized: missing required permission '{$perm}'.");
            }
        }

        // 2. Validate IDs
        $cleanIds = array_values(array_unique(array_filter(array_map(function ($id) {
            return is_numeric($id) ? (int) $id : trim((string) $id);
        }, $ids), fn ($id) => $id !== '' && $id !== 0)));

        if (empty($cleanIds)) {
            return BulkActionResult::failed('No valid record IDs were selected.');
        }

        $modelClass = $this->modelClass();
        if (! is_subclass_of($modelClass, Model::class)) {
            throw new RuntimeException("Target class {$modelClass} must be an Eloquent Model.");
        }

        // 3. Re-query records strictly within College/Tenant scope
        /** @var Builder $query */
        $query = $modelClass::query();

        // Enforce college scope explicitly if column exists, in addition to global scope
        $dummy = new $modelClass();
        $table = $dummy->getTable();
        $hasCollegeId = in_array('college_id', $dummy->getFillable(), true) || property_exists($dummy, 'college_id');

        // Check if query builder has column or model BelongsToCollege
        if (in_array(\App\Domain\Foundation\Traits\BelongsToCollege::class, class_uses_recursive($modelClass), true) || $hasCollegeId) {
            $query->where($dummy->qualifyColumn('college_id'), $college->id);
        }

        $keyName = $dummy->getKeyName();
        $records = $query->whereIn($dummy->qualifyColumn($keyName), $cleanIds)->get();

        // Check for missing or cross-tenant IDs
        $foundCount = $records->count();
        $requestedCount = count($cleanIds);
        $unauthorizedCount = 0;

        if ($foundCount < $requestedCount) {
            $unauthorizedCount += ($requestedCount - $foundCount);
        }

        // 4. Policy-level authorization on individual records
        $ability = $this->policyAbility();
        $authorizedRecords = new Collection();

        foreach ($records as $record) {
            if ($ability !== null) {
                if (Gate::forUser($user)->denies($ability, $record)) {
                    $unauthorizedCount++;
                    continue;
                }
            }
            $authorizedRecords->push($record);
        }

        if ($authorizedRecords->isEmpty()) {
            return BulkActionResult::forbidden("None of the {$requestedCount} selected records could be authorized for this operation.");
        }

        // 5. Delegate to handler
        $result = $this->handle($authorizedRecords, $user, $college, $parameters);

        if ($unauthorizedCount > 0) {
            $result->setSkippedUnauthorizedCount($unauthorizedCount);
        }

        return $result;
    }
}
