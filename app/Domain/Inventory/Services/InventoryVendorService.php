<?php

namespace App\Domain\Inventory\Services;

use App\Models\College;
use App\Models\InventoryVendor;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * InventoryVendorService — CRUD for the tenant-scoped vendor master.
 *
 * Guarantees: a vendor always belongs to the ACTIVE college, and its code is
 * unique among the college's active (not soft-deleted) vendors. Purchase
 * orders are out of scope; a vendor can be archived without touching items.
 */
class InventoryVendorService
{
    private const DUPLICATE_CODE_MESSAGE = 'A vendor with this code already exists for the active college.';

    private const AUDITED = ['name', 'code', 'contact_person', 'phone', 'email', 'address', 'gst_number', 'status'];

    /** Optional free-text fields where an empty form input means "not recorded". */
    private const OPTIONAL = ['contact_person', 'phone', 'email', 'address', 'gst_number'];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(College $college, array $data, User $actor): InventoryVendor
    {
        return DB::transaction(function () use ($college, $data, $actor): InventoryVendor {
            $vendor = new InventoryVendor([
                'college_id' => $college->getKey(),
                'name' => trim((string) $data['name']),
                'code' => $data['code'],
                'status' => $data['status'],
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            foreach (self::OPTIONAL as $field) {
                $vendor->{$field} = $this->optional($data[$field] ?? null);
            }

            $this->assertUniqueActiveCode($vendor);

            try {
                $vendor->save();
            } catch (QueryException) {
                throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
            }

            $this->audit->record('inventory_vendors.created', $vendor, [], $vendor->only(self::AUDITED));

            return $vendor->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(InventoryVendor $vendor, array $data, User $actor): InventoryVendor
    {
        $this->assertTenant($vendor);

        return DB::transaction(function () use ($vendor, $data, $actor): InventoryVendor {
            $old = $vendor->only(self::AUDITED);

            foreach (self::AUDITED as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $vendor->{$field} = match (true) {
                    $field === 'name' => trim((string) $data[$field]),
                    in_array($field, self::OPTIONAL, true) => $this->optional($data[$field]),
                    default => $data[$field],
                };
            }

            $vendor->updated_by = $actor->getKey();

            $this->assertUniqueActiveCode($vendor);

            try {
                $vendor->save();
            } catch (QueryException) {
                throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
            }

            $this->audit->record('inventory_vendors.updated', $vendor, $old, $vendor->only(self::AUDITED));

            return $vendor->refresh();
        });
    }

    public function delete(InventoryVendor $vendor, User $actor): void
    {
        $this->assertTenant($vendor);

        DB::transaction(function () use ($vendor): void {
            $snapshot = $vendor->only(self::AUDITED);
            $vendor->delete();

            $this->audit->record('inventory_vendors.deleted', $vendor, $snapshot, []);
        });
    }

    private function optional(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function assertUniqueActiveCode(InventoryVendor $vendor): void
    {
        $duplicate = InventoryVendor::withoutGlobalScopes()
            ->where('college_id', $vendor->college_id)
            ->where('code', $vendor->code)
            ->whereNull('deleted_at')
            ->when($vendor->exists, fn ($query) => $query->whereKeyNot($vendor->getKey()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
        }
    }

    private function assertTenant(InventoryVendor $vendor): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $vendor->college_id === (int) $collegeId, 403);
    }
}
