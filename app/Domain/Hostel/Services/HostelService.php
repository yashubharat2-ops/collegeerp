<?php

namespace App\Domain\Hostel\Services;

use App\Domain\Foundation\Scopes\CollegeScope;
use App\Models\College;
use App\Models\Hostel;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HostelService — CRUD + integrity rules for the hostel master
 * (Hostel Management, Phase 1).
 *
 * Guarantees:
 *   - a hostel always belongs to the ACTIVE college (college_id comes from
 *     the tenant context — never from request data)
 *   - `code` is unique within the college and stays reserved on archived
 *     records (identifiers must survive history)
 *   - `name` is unique within the college among active (not soft-deleted)
 *     hostels
 *   - a hostel that still has live (not soft-deleted) buildings, rooms or
 *     beds cannot be deleted — mark it inactive instead
 *   - created_by / updated_by are stamped from the authenticated user
 *   - every mutation runs inside a transaction, serialized per college, and
 *     is audited
 */
class HostelService
{
    private const DUPLICATE_CODE_MESSAGE = 'A hostel with this code already exists for the active college (including archived records).';

    private const DUPLICATE_NAME_MESSAGE = 'A hostel with this name already exists for the active college.';

    private const IN_USE_MESSAGE = 'This hostel has buildings, rooms or beds and cannot be deleted. Delete or archive those first, or mark the hostel inactive.';

    private const AUDITED = ['name', 'code', 'hostel_type', 'gender', 'address', 'description', 'status'];

    /** Optional text fields where an empty form input means "not recorded". */
    private const OPTIONAL = ['address', 'description'];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array<string, mixed>  $data  Validated payload.
     */
    public function create(College $college, array $data, User $actor): Hostel
    {
        return DB::transaction(function () use ($college, $data, $actor): Hostel {
            $this->lockCollege($college);

            $hostel = new Hostel([
                // college_id is stamped here from the tenant context — never
                // copied from request data.
                'college_id' => $college->getKey(),
                'name' => trim((string) $data['name']),
                'code' => $data['code'],
                'hostel_type' => $data['hostel_type'],
                'gender' => $data['gender'],
                'status' => $data['status'],
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            foreach (self::OPTIONAL as $field) {
                $hostel->{$field} = $this->optional($data[$field] ?? null);
            }

            $this->assertUniqueIdentifiers($hostel);

            try {
                $hostel->save();
            } catch (QueryException) {
                // The unique index rejected a racing insert.
                throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
            }

            $this->audit->record('hostels.created', $hostel, [], $hostel->only(self::AUDITED));

            return $hostel->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data  Validated payload.
     */
    public function update(Hostel $hostel, array $data, User $actor): Hostel
    {
        $this->assertTenant($hostel);

        return DB::transaction(function () use ($hostel, $data, $actor): Hostel {
            $this->lockWithCollege($hostel);
            $old = $hostel->only(self::AUDITED);

            foreach (self::AUDITED as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $hostel->{$field} = match (true) {
                    $field === 'name' => trim((string) $data[$field]),
                    in_array($field, self::OPTIONAL, true) => $this->optional($data[$field]),
                    default => $data[$field],
                };
            }

            $hostel->updated_by = $actor->getKey();

            $this->assertUniqueIdentifiers($hostel);

            try {
                $hostel->save();
            } catch (QueryException) {
                throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
            }

            $this->audit->record('hostels.updated', $hostel, $old, $hostel->only(self::AUDITED));

            return $hostel->refresh();
        });
    }

    /**
     * Soft delete, refused while live buildings, rooms or beds reference the
     * hostel: the hierarchy under an existing hostel must never silently lose
     * its anchor. Marking it inactive preserves everything.
     */
    public function delete(Hostel $hostel, User $actor): void
    {
        $this->assertTenant($hostel);

        DB::transaction(function () use ($hostel): void {
            $this->lockWithCollege($hostel);

            if ($hostel->buildings()->exists() || $hostel->rooms()->exists() || $hostel->beds()->exists()) {
                throw ValidationException::withMessages(['hostel' => self::IN_USE_MESSAGE]);
            }

            $snapshot = $hostel->only(self::AUDITED);
            $hostel->delete();

            $this->audit->record('hostels.deleted', $hostel, $snapshot, []);
        });
    }

    private function assertUniqueIdentifiers(Hostel $hostel): void
    {
        // Codes stay reserved on archived records: any row of this college
        // (including soft-deleted ones) blocks the code.
        $duplicateCode = Hostel::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $hostel->college_id)
            ->where('code', $hostel->code)
            ->when($hostel->exists, fn ($query) => $query->whereKeyNot($hostel->getKey()))
            ->exists();

        if ($duplicateCode) {
            throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
        }

        // Names must be unique among the college's ACTIVE hostels only:
        // archiving frees the name, archiving never frees the code.
        $duplicateName = Hostel::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $hostel->college_id)
            ->where('name', $hostel->name)
            ->whereNull('deleted_at')
            ->when($hostel->exists, fn ($query) => $query->whereKeyNot($hostel->getKey()))
            ->exists();

        if ($duplicateName) {
            throw ValidationException::withMessages(['name' => self::DUPLICATE_NAME_MESSAGE]);
        }
    }

    private function optional(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function assertTenant(Hostel $hostel): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $hostel->college_id === (int) $collegeId, 403);
    }

    private function lockCollege(College $college): void
    {
        College::query()->whereKey($college->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockWithCollege(Hostel $hostel): void
    {
        College::query()->whereKey($hostel->college_id)->lockForUpdate()->firstOrFail();
    }
}
