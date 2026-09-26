<?php

namespace App\Domain\Inventory\Services;

use App\Models\College;
use App\Models\Faculty;
use App\Models\InventoryIssue;
use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * InventoryIssueService — item issue / allocation (Phase 3).
 *
 * Consumable stock leaves the college through the EXISTING stock ledger,
 * never through this table: the issue first writes a `stock_out` movement
 * via InventoryStockService (which owns the row lock, the negative-balance
 * guard and the balance snapshot), then records the allocation behind it —
 * the recipient, the purpose and the auto-generated issue number that the
 * movement carries as its reference. Both writes happen in one transaction,
 * so an issue can never reduce stock without a ledger row, and a ledger row
 * of this kind can never exist without an issue.
 *
 * Guarantees:
 *   - the item belongs to the ACTIVE college (re-checked here even when the
 *     caller came through a Form Request);
 *   - only ACTIVE consumables may be issued — assets are individual items
 *     that move through assignment, not stock;
 *   - the recipient is an existing, same-tenant student or staff member;
 *   - quantity is positive and cannot drive the balance negative
 *     (InventoryStockService rejects it with a field error);
 *   - `number` is unique per college; a concurrent-issue collision is
 *     retried against the unique index, never hand-merged.
 */
class InventoryIssueService
{
    private const RECIPIENT_TYPES = ['student' => Student::class, 'faculty' => Faculty::class];

    private const AUDITED = [
        'item_id',
        'number',
        'quantity',
        'issued_to_type',
        'issued_to_id',
        'purpose',
        'reference',
        'movement_date',
        'notes',
    ];

    public function __construct(
        private readonly AuditLogService $audit,
        private readonly InventoryStockService $stock,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(College $college, array $data, User $actor): InventoryIssue
    {
        $item = InventoryItem::query()->findOrFail($data['item_id']);
        $this->assertTenant($item);
        $this->assertIssuable($item);
        $this->assertRecipient($data['issued_to_type'], $data['issued_to_id'], $item->college_id);

        $quantity = $this->quantity($data['quantity'] ?? null);
        $movementDate = $data['movement_date'] ?? now()->toDateString();

        return $this->withNumberRetry(function () use ($college, $item, $data, $actor, $quantity, $movementDate): InventoryIssue {
            $number = $this->nextNumber($college);

            return DB::transaction(function () use ($item, $data, $actor, $number, $quantity, $movementDate): InventoryIssue {
                // The ledger write comes first: it locks the item row,
                // enforces the negative-balance guard and snapshots the
                // balance. If it throws, nothing of this issue is written.
                $this->stock->apply(
                    $item,
                    InventoryStockMovement::TYPE_STOCK_OUT,
                    $quantity,
                    InventoryStockMovement::DIRECTION_OUT,
                    $actor,
                    [
                        'reference' => $number,
                        'reason' => $this->optionalText($data['purpose'] ?? null) ?? 'Item issue',
                        'notes' => $this->optionalText($data['notes'] ?? null),
                        'movement_date' => $movementDate,
                    ],
                );

                $issue = InventoryIssue::create([
                    'college_id' => $item->college_id,
                    'item_id' => $item->getKey(),
                    'number' => $number,
                    'quantity' => $quantity,
                    'issued_to_type' => $data['issued_to_type'],
                    'issued_to_id' => (int) $data['issued_to_id'],
                    'purpose' => $this->optionalText($data['purpose'] ?? null),
                    'reference' => $this->optionalText($data['reference'] ?? null),
                    'movement_date' => $movementDate,
                    'notes' => $this->optionalText($data['notes'] ?? null),
                    'created_by' => $actor->getKey(),
                ]);

                $this->audit->record('inventory_issues.created', $issue, [], $issue->only(self::AUDITED));

                return $issue;
            });
        });
    }

    private function withNumberRetry(callable $attempt): InventoryIssue
    {
        for ($i = 0; $i < 3; $i++) {
            try {
                return $attempt();
            } catch (QueryException $exception) {
                if ($i === 2 || ! $this->isUniqueNumberViolation($exception)) {
                    throw $exception;
                }

                // Two concurrent issues picked the same number: recompute
                // from the (now higher) maximum and try again.
            }
        }

        throw new \RuntimeException('Unreachable.');
    }

    private function isUniqueNumberViolation(QueryException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'unique');
    }

    /** ISS-000001, ISS-000002, … per college, from the current maximum. */
    private function nextNumber(College $college): string
    {
        $last = InventoryIssue::query()
            ->where('college_id', $college->getKey())
            ->where('number', 'like', 'ISS-%')
            ->orderByDesc('number')
            ->value('number');

        // First issue of a college is ISS-000001; afterwards the number
        // continues from the college's current maximum.
        $sequence = $last === null ? 1 : ((int) substr($last, 4)) + 1;

        return 'ISS-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    /** An issue is only valid for stock that can actually be handed out. */
    private function assertIssuable(InventoryItem $item): void
    {
        if (! $item->isActive()) {
            throw ValidationException::withMessages([
                'item_id' => "Inactive items cannot be issued. Re-activate \"{$item->name}\" first.",
            ]);
        }

        if ($item->item_type !== InventoryItem::TYPE_CONSUMABLE) {
            throw ValidationException::withMessages([
                'item_id' => "\"{$item->name}\" is an asset, not a consumable. Assign it through Asset Assignment instead.",
            ]);
        }
    }

    private function assertRecipient(string $type, mixed $id, int $collegeId): void
    {
        $model = self::RECIPIENT_TYPES[$type] ?? null;

        if ($model === null) {
            throw ValidationException::withMessages([
                'issued_to_type' => 'The recipient must be a student or a staff member.',
            ]);
        }

        $exists = $model::withoutGlobalScopes()
            ->whereKey((int) $id)
            ->where('college_id', $collegeId)
            ->when($model === Student::class, fn ($query) => $query->whereNull('deleted_at'))
            ->when($model === Faculty::class, fn ($query) => $query->whereNull('deleted_at'))
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'issued_to_id' => 'The recipient does not belong to the active college.',
            ]);
        }
    }

    /** Quantities are kept as exact decimal strings — bcadd avoids binary-float rounding. */
    private function quantity(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 2);
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
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $item->college_id === (int) $collegeId, 403);
    }
}
