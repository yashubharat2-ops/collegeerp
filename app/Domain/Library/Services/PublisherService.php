<?php

namespace App\Domain\Library\Services;

use App\Models\College;
use App\Models\Publisher;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PublisherService — CRUD for the tenant-scoped, reusable publisher master.
 *
 * Guarantees: a publisher always belongs to the ACTIVE college, no two active
 * (not soft-deleted) publishers of a college share a name (compared
 * case-insensitively with collapsed whitespace), and a publisher still
 * referenced by books cannot be removed.
 */
class PublisherService
{
    private const DUPLICATE_NAME_MESSAGE = 'A publisher with this name already exists for the active college.';

    private const IN_USE_MESSAGE = 'This publisher is still referenced by books and cannot be deleted. Mark the publisher inactive instead.';

    private const AUDITED = ['name', 'email', 'phone', 'website', 'address', 'status', 'description'];

    /** Optional free-text fields where an empty form input means "not recorded". */
    private const OPTIONAL = ['email', 'phone', 'website', 'address', 'description'];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array{name: string, status: string, email?: string|null, phone?: string|null, website?: string|null, address?: string|null, description?: string|null}  $data
     */
    public function create(College $college, array $data, User $actor): Publisher
    {
        return DB::transaction(function () use ($college, $data, $actor): Publisher {
            $publisher = new Publisher([
                'college_id' => $college->getKey(),
                'name' => trim($data['name']),
                'status' => $data['status'],
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            foreach (self::OPTIONAL as $field) {
                $publisher->{$field} = ($data[$field] ?? null) ?: null;
            }

            $this->assertUniqueActiveName($publisher);

            try {
                $publisher->save();
            } catch (QueryException) {
                throw ValidationException::withMessages(['name' => self::DUPLICATE_NAME_MESSAGE]);
            }

            $this->audit->record('publishers.created', $publisher, [], $publisher->only(self::AUDITED));

            return $publisher->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Publisher $publisher, array $data, User $actor): Publisher
    {
        $this->assertTenant($publisher);

        return DB::transaction(function () use ($publisher, $data, $actor): Publisher {
            $old = $publisher->only(self::AUDITED);

            foreach (self::AUDITED as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $publisher->{$field} = match (true) {
                    $field === 'name' => trim((string) $data[$field]),
                    in_array($field, self::OPTIONAL, true) => $data[$field] ?: null,
                    default => $data[$field],
                };
            }

            $publisher->updated_by = $actor->getKey();

            $this->assertUniqueActiveName($publisher);

            try {
                $publisher->save();
            } catch (QueryException) {
                throw ValidationException::withMessages(['name' => self::DUPLICATE_NAME_MESSAGE]);
            }

            $this->audit->record('publishers.updated', $publisher, $old, $publisher->only(self::AUDITED));

            return $publisher->refresh();
        });
    }

    /**
     * Soft delete. Refused while books still reference the publisher.
     */
    public function delete(Publisher $publisher, User $actor): void
    {
        $this->assertTenant($publisher);

        DB::transaction(function () use ($publisher): void {
            if ($publisher->books()->exists()) {
                throw ValidationException::withMessages(['publisher' => self::IN_USE_MESSAGE]);
            }

            $snapshot = $publisher->only(self::AUDITED);
            $publisher->delete();

            $this->audit->record('publishers.deleted', $publisher, $snapshot, []);
        });
    }

    private function assertUniqueActiveName(Publisher $publisher): void
    {
        $duplicate = Publisher::withoutGlobalScopes()
            ->where('college_id', $publisher->college_id)
            ->where('name_normalized', Publisher::normalizeName((string) $publisher->name))
            ->whereNull('deleted_at')
            ->when($publisher->exists, fn ($query) => $query->whereKeyNot($publisher->getKey()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => self::DUPLICATE_NAME_MESSAGE]);
        }
    }

    private function assertTenant(Publisher $publisher): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $publisher->college_id === (int) $collegeId, 403);
    }
}
