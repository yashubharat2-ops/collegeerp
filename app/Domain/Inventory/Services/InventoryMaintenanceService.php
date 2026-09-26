<?php

namespace App\Domain\Inventory\Services;

use App\Models\College;
use App\Models\InventoryItem;
use App\Models\InventoryMaintenance;
use App\Models\InventoryVendor;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * InventoryMaintenanceService — asset maintenance (Phase 3).
 *
 * A maintenance record is always LINKED TO AN EXISTING ASSET: it references
 * an `inventory_items` row with `item_type = 'asset'` through the composite
 * tenant foreign key, and nothing in this module creates or duplicates an
 * asset. Stock is never involved — maintenance is work on the item, not a
 * movement of it.
 *
 * A record is a live work order: it can be edited as the work progresses
 * (status walks scheduled → in progress → completed, costs and the
 * completion date are filled in along the way). Editing is the correction
 * path; there is no delete.
 */
class InventoryMaintenanceService
{
    private const AUDITED = [
        'item_id',
        'vendor_id',
        'title',
        'maintenance_type',
        'status',
        'scheduled_on',
        'completed_on',
        'cost',
        'performed_by',
        'description',
    ];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(College $college, array $data, User $actor): InventoryMaintenance
    {
        $item = InventoryItem::query()->findOrFail($data['item_id']);
        $this->assertTenant($item);
        $this->assertMaintainable($item);
        $this->assertVendor($data['vendor_id'] ?? null, $item->college_id);
        $this->assertDates($data);

        return DB::transaction(function () use ($item, $data, $actor): InventoryMaintenance {
            $maintenance = InventoryMaintenance::create([
                'college_id' => $item->college_id,
                'item_id' => $item->getKey(),
                'vendor_id' => $data['vendor_id'] !== null && $data['vendor_id'] !== '' ? (int) $data['vendor_id'] : null,
                'title' => trim((string) $data['title']),
                'maintenance_type' => $data['maintenance_type'],
                'status' => $data['status'],
                'scheduled_on' => $this->optionalDate($data['scheduled_on'] ?? null),
                'completed_on' => $this->optionalDate($data['completed_on'] ?? null),
                'cost' => $this->optionalAmount($data['cost'] ?? null),
                'performed_by' => $this->optionalText($data['performed_by'] ?? null),
                'description' => $this->optionalText($data['description'] ?? null),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->audit->record('inventory_maintenances.created', $maintenance, [], $maintenance->only(self::AUDITED));

            return $maintenance->refresh();
        });
    }

    /**
     * Update an existing record (status walk, cost, dates, details).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(InventoryMaintenance $maintenance, array $data, User $actor): InventoryMaintenance
    {
        // The row carries its own college_id; re-check it against the active
        // tenant so a forged id from another college is a 403, not an edit.
        $this->assertTenantCollege($maintenance->college_id);

        return DB::transaction(function () use ($maintenance, $data, $actor): InventoryMaintenance {
            $old = $maintenance->only(self::AUDITED);

            if (array_key_exists('title', $data)) {
                $maintenance->title = trim((string) $data['title']);
            }

            if (array_key_exists('maintenance_type', $data)) {
                $maintenance->maintenance_type = $data['maintenance_type'];
            }

            if (array_key_exists('status', $data)) {
                $maintenance->status = $data['status'];
            }

            if (array_key_exists('vendor_id', $data)) {
                $value = $data['vendor_id'];
                if ($value !== null && $value !== '') {
                    $this->assertVendor((int) $value, $maintenance->college_id);
                }

                $maintenance->vendor_id = ($value !== null && $value !== '') ? (int) $value : null;
            }

            if (array_key_exists('scheduled_on', $data)) {
                $maintenance->scheduled_on = $this->optionalDate($data['scheduled_on']);
            }

            if (array_key_exists('completed_on', $data)) {
                $maintenance->completed_on = $this->optionalDate($data['completed_on']);
            }

            if (array_key_exists('cost', $data)) {
                $maintenance->cost = $this->optionalAmount($data['cost']);
            }

            if (array_key_exists('performed_by', $data)) {
                $maintenance->performed_by = $this->optionalText($data['performed_by']);
            }

            if (array_key_exists('description', $data)) {
                $maintenance->description = $this->optionalText($data['description']);
            }

            $this->assertDates([
                'status' => $maintenance->status,
                'completed_on' => $maintenance->completed_on,
            ]);

            $maintenance->updated_by = $actor->getKey();
            $maintenance->save();

            $this->audit->record('inventory_maintenances.updated', $maintenance, $old, $maintenance->only(self::AUDITED));

            return $maintenance->refresh();
        });
    }

    private function assertMaintainable(InventoryItem $item): void
    {
        if ($item->item_type !== InventoryItem::TYPE_ASSET) {
            throw ValidationException::withMessages([
                'item_id' => "Maintenance is recorded for assets only; \"{$item->name}\" is a consumable.",
            ]);
        }
    }

    private function assertVendor(mixed $vendorId, int $collegeId): void
    {
        if ($vendorId === null || $vendorId === '' || $vendorId === '0' || $vendorId === 0) {
            return;
        }

        $exists = InventoryVendor::withoutGlobalScopes()
            ->whereKey((int) $vendorId)
            ->where('college_id', $collegeId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'vendor_id' => 'The vendor does not belong to the active college.',
            ]);
        }
    }

    /** A completed work order must carry its completion date. */
    private function assertDates(array $data): void
    {
        if (($data['status'] ?? null) === InventoryMaintenance::STATUS_COMPLETED
            && ($data['completed_on'] ?? null) === null
        ) {
            throw ValidationException::withMessages([
                'completed_on' => 'A completed maintenance needs its completion date.',
            ]);
        }
    }

    private function optionalDate(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }

    private function optionalAmount(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return bcadd((string) $value, '0', 2);
    }

    private function optionalText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function assertTenant(InventoryItem $item): void
    {
        $this->assertTenantCollege($item->college_id);
    }

    private function assertTenantCollege(int $collegeId): void
    {
        $active = app(TenantContext::class)->id();

        abort_unless($active !== null && (int) $collegeId === (int) $active, 403);
    }
}
