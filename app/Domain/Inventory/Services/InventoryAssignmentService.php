<?php

namespace App\Domain\Inventory\Services;

use App\Models\College;
use App\Models\Faculty;
use App\Models\InventoryAssignment;
use App\Models\InventoryItem;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * InventoryAssignmentService — asset assignment and return (Phase 3).
 *
 * Assignment is custody, not consumption: the asset's on-hand quantity and
 * the stock ledger are untouched. The service owns the two rules the rest
 * of the module depends on:
 *
 *   - an asset cannot have multiple active assignments. The check runs
 *     inside a transaction that LOCKS THE ITEM ROW, so two concurrent
 *     assignments cannot both pass it (on SQLite/PostgreSQL a partial
 *     unique index is the second, database-level guard);
 *   - returning an asset preserves its history: the active row flips to
 *     `returned` with the return date, actor and notes — it is never
 *     deleted — and a later re-assignment is a NEW row, so the full
 *     custody trail of the asset stays in place.
 */
class InventoryAssignmentService
{
    private const ASSIGNEE_TYPES = ['student' => Student::class, 'faculty' => Faculty::class];

    private const DUPLICATE_ASSIGNMENT_MESSAGE = 'This asset already has an active assignment. Return it before assigning it again.';

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * Assign an asset.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(College $college, array $data, User $actor): InventoryAssignment
    {
        $item = InventoryItem::query()->findOrFail($data['item_id']);
        $this->assertTenant($item);
        $this->assertAssignable($item);
        $this->assertAssignee($data['assigned_to_type'], $data['assigned_to_id'], $item->college_id);

        return DB::transaction(function () use ($item, $data, $actor): InventoryAssignment {
            // Lock the item row so two concurrent assignments cannot both
            // see "no active assignment" for the same asset. (The terminal
            // firstOrFail() is what executes the SELECT … FOR UPDATE.)
            InventoryItem::query()
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $active = InventoryAssignment::query()
                ->where('item_id', $item->getKey())
                ->where('status', InventoryAssignment::STATUS_ACTIVE)
                ->exists();

            if ($active) {
                throw ValidationException::withMessages([
                    'item_id' => self::DUPLICATE_ASSIGNMENT_MESSAGE,
                ]);
            }

            $assignment = InventoryAssignment::create([
                'college_id' => $item->college_id,
                'item_id' => $item->getKey(),
                'assigned_to_type' => $data['assigned_to_type'],
                'assigned_to_id' => (int) $data['assigned_to_id'],
                'purpose' => $this->optionalText($data['purpose'] ?? null),
                'assigned_on' => $data['assigned_on'] ?? now()->toDateString(),
                'status' => InventoryAssignment::STATUS_ACTIVE,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->audit->record('inventory_assignments.created', $assignment, [], [
                'item_id' => $assignment->item_id,
                'assigned_to_type' => $assignment->assigned_to_type,
                'assigned_to_id' => $assignment->assigned_to_id,
                'assigned_on' => $assignment->assigned_on,
                'status' => $assignment->status,
            ]);

            return $assignment;
        });
    }

    /**
     * Return an assigned asset. The row is updated in place — its history
     * (who held it, since when, who took it back, notes) is preserved.
     *
     * @param  array<string, mixed>  $data
     */
    public function returnAsset(InventoryAssignment $assignment, array $data, User $actor): InventoryAssignment
    {
        // The row carries its own college_id; re-check it against the active
        // tenant so a forged id from another college is a 403, not an edit.
        $this->assertTenantCollege($assignment->college_id);

        return DB::transaction(function () use ($assignment, $data, $actor): InventoryAssignment {
            InventoryItem::query()
                ->whereKey($assignment->item_id)
                ->lockForUpdate()
                ->firstOrFail();

            $fresh = InventoryAssignment::query()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $fresh->isActive()) {
                throw ValidationException::withMessages([
                    'assignment_id' => 'This asset is already returned. Its history is preserved — a new lending is a new assignment.',
                ]);
            }

            $old = [
                'status' => $fresh->status,
                'returned_on' => $fresh->returned_on,
                'returned_by' => $fresh->returned_by,
                'return_notes' => $fresh->return_notes,
            ];

            $fresh->status = InventoryAssignment::STATUS_RETURNED;
            $fresh->returned_on = $data['returned_on'] ?? now()->toDateString();
            $fresh->returned_by = $actor->getKey();
            $fresh->return_notes = $this->optionalText($data['return_notes'] ?? null);
            $fresh->updated_by = $actor->getKey();
            $fresh->save();

            $this->audit->record('inventory_assignments.returned', $fresh, $old, [
                'status' => $fresh->status,
                'returned_on' => $fresh->returned_on,
                'returned_by' => $fresh->returned_by,
                'return_notes' => $fresh->return_notes,
            ]);

            return $fresh->refresh();
        });
    }

    private function assertAssignable(InventoryItem $item): void
    {
        if ($item->item_type !== InventoryItem::TYPE_ASSET) {
            throw ValidationException::withMessages([
                'item_id' => "\"{$item->name}\" is a consumable, not an asset. Issue its stock through Item Issue / Allocation instead.",
            ]);
        }

        if (! $item->isActive()) {
            throw ValidationException::withMessages([
                'item_id' => "Inactive assets cannot be assigned. Re-activate \"{$item->name}\" first.",
            ]);
        }
    }

    private function assertAssignee(string $type, mixed $id, int $collegeId): void
    {
        $model = self::ASSIGNEE_TYPES[$type] ?? null;

        if ($model === null) {
            throw ValidationException::withMessages([
                'assigned_to_type' => 'The assignee must be a student or a staff member.',
            ]);
        }

        $exists = $model::withoutGlobalScopes()
            ->whereKey((int) $id)
            ->where('college_id', $collegeId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'assigned_to_id' => 'The assignee does not belong to the active college.',
            ]);
        }
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
