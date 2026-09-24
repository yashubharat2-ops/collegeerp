<?php

namespace App\Domain\Hostel\Services;

use App\Domain\Foundation\Scopes\CollegeScope;
use App\Models\College;
use App\Models\Hostel;
use App\Models\HostelBuilding;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HostelBuildingService — CRUD + integrity rules for buildings / blocks
 * (Hostel Management, Phase 1).
 *
 * Guarantees:
 *   - the building always belongs to the ACTIVE college (stamped from the
 *     tenant context) and to a live hostel of the SAME college (contextual
 *     foreign-key guard, re-checked here even if the Form Request was bypassed)
 *   - the parent hostel is fixed at creation; buildings are never reparented
 *   - `code` is unique within the hostel (and college) and stays reserved on
 *     archived records
 *   - `name` is unique within the hostel among active (not soft-deleted)
 *     buildings
 *   - a building that still has live rooms or beds cannot be deleted
 *   - transactions, per-college serialization and audit on every mutation
 */
class HostelBuildingService
{
    private const DUPLICATE_CODE_MESSAGE = 'A building / block with this code already exists in this hostel (including archived records).';

    private const DUPLICATE_NAME_MESSAGE = 'A building / block with this name already exists in this hostel.';

    private const FOREIGN_HOSTEL_MESSAGE = 'The selected hostel does not belong to the active college.';

    private const IMMUTABLE_HOSTEL_MESSAGE = 'A building / block cannot be moved to another hostel. Create a new building in the target hostel instead.';

    private const IN_USE_MESSAGE = 'This building / block has rooms or beds and cannot be deleted. Delete or archive those first, or mark the building inactive.';

    private const AUDITED = ['name', 'code', 'floors', 'description', 'status'];

    private const OPTIONAL = ['floors', 'description'];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array<string, mixed>  $data  Validated payload; `hostel_id` is the chosen parent.
     */
    public function create(College $college, array $data, User $actor): HostelBuilding
    {
        return DB::transaction(function () use ($college, $data, $actor): HostelBuilding {
            $this->lockCollege($college);

            $building = new HostelBuilding([
                'college_id' => $college->getKey(),
                'hostel_id' => (int) $data['hostel_id'],
                'name' => trim((string) $data['name']),
                'code' => $data['code'],
                'status' => $data['status'],
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            foreach (self::OPTIONAL as $field) {
                $building->{$field} = $this->optional($data[$field] ?? null);
            }

            $this->assertHostelBelongsToCollege($building);
            $this->assertUniqueIdentifiers($building);

            try {
                $building->save();
            } catch (QueryException) {
                throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
            }

            $this->audit->record('hostel_buildings.created', $building, [], $this->snapshot($building));

            return $building->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data  Validated payload.
     */
    public function update(HostelBuilding $building, array $data, User $actor): HostelBuilding
    {
        $this->assertTenant($building);

        return DB::transaction(function () use ($building, $data, $actor): HostelBuilding {
            $this->lockWithCollege($building);

            if (array_key_exists('hostel_id', $data) && (int) $data['hostel_id'] !== (int) $building->hostel_id) {
                throw ValidationException::withMessages(['hostel_id' => self::IMMUTABLE_HOSTEL_MESSAGE]);
            }

            $old = $this->snapshot($building);

            foreach (self::AUDITED as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $building->{$field} = match (true) {
                    $field === 'name' => trim((string) $data[$field]),
                    in_array($field, self::OPTIONAL, true) => $this->optional($data[$field]),
                    default => $data[$field],
                };
            }

            $building->updated_by = $actor->getKey();

            $this->assertUniqueIdentifiers($building);

            try {
                $building->save();
            } catch (QueryException) {
                throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
            }

            $this->audit->record('hostel_buildings.updated', $building, $old, $this->snapshot($building));

            return $building->refresh();
        });
    }

    /**
     * Soft delete, refused while live rooms or beds reference the building.
     */
    public function delete(HostelBuilding $building, User $actor): void
    {
        $this->assertTenant($building);

        DB::transaction(function () use ($building): void {
            $this->lockWithCollege($building);

            if ($building->rooms()->exists() || $building->beds()->exists()) {
                throw ValidationException::withMessages(['building' => self::IN_USE_MESSAGE]);
            }

            $snapshot = $this->snapshot($building);
            $building->delete();

            $this->audit->record('hostel_buildings.deleted', $building, $snapshot, []);
        });
    }

    /**
     * Contextual FK guard: the parent hostel must be a live row of the
     * building's own college.
     */
    private function assertHostelBelongsToCollege(HostelBuilding $building): void
    {
        $ok = Hostel::withoutGlobalScope(CollegeScope::class)
            ->whereKey($building->hostel_id)
            ->where('college_id', $building->college_id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $ok) {
            throw ValidationException::withMessages(['hostel_id' => self::FOREIGN_HOSTEL_MESSAGE]);
        }
    }

    private function assertUniqueIdentifiers(HostelBuilding $building): void
    {
        $base = fn () => HostelBuilding::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $building->college_id)
            ->where('hostel_id', $building->hostel_id)
            ->when($building->exists, fn ($query) => $query->whereKeyNot($building->getKey()));

        // Codes stay reserved on archived records.
        if ($base()->where('code', $building->code)->exists()) {
            throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
        }

        // Names are unique among the hostel's ACTIVE buildings only.
        $duplicateName = HostelBuilding::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $building->college_id)
            ->where('hostel_id', $building->hostel_id)
            ->where('name', $building->name)
            ->whereNull('deleted_at')
            ->when($building->exists, fn ($query) => $query->whereKeyNot($building->getKey()))
            ->exists();

        if ($duplicateName) {
            throw ValidationException::withMessages(['name' => self::DUPLICATE_NAME_MESSAGE]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(HostelBuilding $building): array
    {
        return $building->only(self::AUDITED) + ['hostel_id' => (int) $building->hostel_id];
    }

    private function optional(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : (trim((string) $value) ?: null);
    }

    private function assertTenant(HostelBuilding $building): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $building->college_id === (int) $collegeId, 403);
    }

    private function lockCollege(College $college): void
    {
        College::query()->whereKey($college->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockWithCollege(HostelBuilding $building): void
    {
        College::query()->whereKey($building->college_id)->lockForUpdate()->firstOrFail();
    }
}
