<?php

namespace App\Domain\Finance\Services;

use App\Models\College;
use App\Models\FeeStructure;
use App\Models\FeeStructureItem;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FeeStructureService — CRUD + structural validation for the Fee Structure
 * foundation of the Finance / Fees module.
 *
 * The service owns no fee policy of its own: every fee head and amount lives in
 * FeeStructureItem rows configured by the college. What it does guarantee is
 * that a saved structure is structurally sound:
 *
 *   - the structure and every item belong to the ACTIVE college
 *   - academic year / program / term are contextual FKs of that college, and a
 *     term always belongs to the structure's academic year
 *   - a fee head has a name and a NON-NEGATIVE amount
 *   - no duplicate fee head inside one structure
 *   - no duplicate active structure per (college, academic year, program, code)
 *   - deterministic sort_order
 *
 * Tenant safety: college_id is always taken from the authenticated tenant
 * context; a browser-supplied college_id never reaches the database.
 *
 * Scope: definitions only. Fee collection, receipts, discounts, refunds and
 * reports are intentionally not implemented here.
 */
class FeeStructureService
{
    private const AUDITED = [
        'name',
        'code',
        'academic_year_id',
        'program_id',
        'academic_term_id',
        'status',
        'description',
    ];

    private const DUPLICATE_CODE_MESSAGE = 'A fee structure with this code already exists for the selected academic year and program.';

    private const ITEM_AUDITED = [
        // Optional classification only — the fee head keeps its own name.
        'fee_category_id',
        'name',
        'amount',
        'description',
        'sort_order',
        'status',
    ];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array{name: string, code: string, academic_year_id: int, program_id: int, academic_term_id?: int|null, status: string, description?: string|null, items?: array<int, array>}  $data
     */
    public function create(College $college, array $data, User $actor): FeeStructure
    {
        return DB::transaction(function () use ($college, $data, $actor): FeeStructure {
            $structure = new FeeStructure([
                // college_id is stamped here from the tenant context — never
                // copied from request data.
                'college_id' => $college->getKey(),
                'academic_year_id' => $data['academic_year_id'],
                'program_id' => $data['program_id'],
                'academic_term_id' => $data['academic_term_id'] ?? null,
                'name' => $data['name'],
                'code' => $data['code'],
                'status' => $data['status'],
                // An empty textarea is "no description", not an empty string.
                'description' => ($data['description'] ?? null) ?: null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->assertUniqueActiveStructure($structure);

            try {
                $structure->save();
            } catch (QueryException) {
                // Partial unique index (SQLite/Postgres) rejected a racing
                // insert; MySQL/MariaDB rely on the guard above.
                throw ValidationException::withMessages([
                    'code' => self::DUPLICATE_CODE_MESSAGE,
                ]);
            }

            $this->syncItems($structure, $data['items'] ?? [], $college->getKey());

            $this->audit->record('fee_structures.created', $structure, [], $structure->only(self::AUDITED));

            return $structure->refresh();
        });
    }

    /**
     * @param  array{name?: string, code?: string, academic_year_id?: int, program_id?: int, academic_term_id?: int|null, status?: string, description?: string|null, items?: array<int, array>}  $data
     */
    public function update(FeeStructure $structure, array $data, User $actor): FeeStructure
    {
        $this->assertTenant($structure);

        return DB::transaction(function () use ($structure, $data, $actor): FeeStructure {
            $old = $structure->only(self::AUDITED);

            foreach (self::AUDITED as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                // An empty textarea is "no description", not an empty string.
                $structure->{$field} = $field === 'description'
                    ? ($data[$field] ?: null)
                    : $data[$field];
            }
            $structure->updated_by = $actor->getKey();

            $this->assertUniqueActiveStructure($structure);

            try {
                $structure->save();
            } catch (QueryException) {
                throw ValidationException::withMessages([
                    'code' => self::DUPLICATE_CODE_MESSAGE,
                ]);
            }

            if (array_key_exists('items', $data)) {
                $this->syncItems($structure, $data['items'] ?? [], $structure->college_id);
            }

            $this->audit->record('fee_structures.updated', $structure, $old, $structure->only(self::AUDITED));

            return $structure->refresh();
        });
    }

    /**
     * Soft delete. The structure and its fee heads stop being visible while the
     * audit trail (and the row itself) remains intact — nothing is destroyed.
     */
    public function delete(FeeStructure $structure, User $actor): void
    {
        $this->assertTenant($structure);

        DB::transaction(function () use ($structure): void {
            $snapshot = $structure->only(self::AUDITED) + [
                'items' => $structure->items()->get()->map->only(self::ITEM_AUDITED)->all(),
            ];

            // Fee heads follow their parent structure.
            $structure->allItems()->delete();
            $structure->delete();

            $this->audit->record('fee_structures.deleted', $structure, $snapshot, []);
        });
    }

    /**
     * Validate a set of fee heads. Throws the first structural problem found so
     * the UI can point at the offending row.
     *
     * Called by the Form Requests (write path) so the HTTP validation messages
     * and the service's own guarantees can never drift apart.
     *
     * @param  array<int, array>  $items  Raw rows (before normalisation).
     */
    public function validateItems(array $items): void
    {
        $this->assertItemRules($this->normaliseItems($items));
    }

    /**
     * Replace the structure's fee heads with the submitted set, fully validated.
     *
     * Rows carrying an id that belongs to this structure are updated in place;
     * everything else is created, and rows dropped from the submission are
     * removed — each change is audited. Removals are applied before the new set
     * is written, because fee-head names are unique inside a structure and a
     * re-used name must not collide with the row that is on its way out.
     *
     * @param  array<int, array>  $items
     */
    private function syncItems(FeeStructure $structure, array $items, int $collegeId): void
    {
        $normalised = $this->normaliseItems($items);

        $this->assertItemRules($normalised);

        // Resolve the submitted rows against the fee heads that really belong
        // to this structure *before* writing: heads dropped from the submission
        // are removed first, because a re-used name would otherwise collide with
        // the (fee_structure_id, name) unique index while the old row is still
        // present.
        $existing = $structure->allItems()->get()->keyBy(fn (FeeStructureItem $item): int => (int) $item->getKey());

        $targets = [];

        foreach ($normalised as $index => $row) {
            $targets[$index] = $row['id'] !== null ? $existing->get((int) $row['id']) : null;
        }

        $keptIds = array_values(array_filter(
            array_map(fn (?FeeStructureItem $item): ?int => $item?->getKey(), $targets),
            fn (?int $id): bool => $id !== null,
        ));

        // Fee heads removed from the submitted set no longer belong to the
        // structure; the removal itself is audit-logged.
        foreach ($existing as $item) {
            if (in_array((int) $item->getKey(), $keptIds, true)) {
                continue;
            }

            $snapshot = $item->only(self::ITEM_AUDITED);
            $item->delete();

            $this->audit->record('fee_structure_items.deleted', $item, $snapshot, []);
        }

        foreach ($normalised as $index => $row) {
            $attributes = [
                'college_id' => $collegeId,
                'fee_category_id' => $row['fee_category_id'],
                'name' => $row['name'],
                'amount' => $row['amount'],
                'description' => $row['description'],
                // Deterministic ordering: explicit sort_order when given,
                // otherwise the order the fee heads were submitted in.
                'sort_order' => $row['sort_order'] ?? $index,
                'status' => $row['status'],
            ];

            $item = $targets[$index] ?? null;

            if ($item) {
                $old = $item->only(self::ITEM_AUDITED);
                $item->fill($attributes)->save();
                $this->audit->record('fee_structure_items.updated', $item, $old, $item->only(self::ITEM_AUDITED));
            } else {
                $item = $structure->allItems()->create($attributes);
                $this->audit->record('fee_structure_items.created', $item, [], $item->only(self::ITEM_AUDITED));
            }
        }
    }

    /**
     * Normalise raw fee-head rows into a strictly typed, deterministic list.
     *
     * @param  array<int, array>  $items
     * @return list<array{id: int|null, fee_category_id: int|null, name: string, amount: float|null, description: string|null, sort_order: int|null, status: string}>
     */
    private function normaliseItems(array $items): array
    {
        $normalised = [];

        foreach (array_values($items) as $row) {
            $amount = $row['amount'] ?? null;
            $sortOrder = $row['sort_order'] ?? null;

            $categoryId = $row['fee_category_id'] ?? null;

            $normalised[] = [
                'id' => isset($row['id']) && $row['id'] !== '' ? (int) $row['id'] : null,
                'fee_category_id' => $categoryId === null || $categoryId === '' ? null : (int) $categoryId,
                'name' => trim((string) ($row['name'] ?? '')),
                'amount' => $amount === null || $amount === '' ? null : round((float) $amount, 2),
                'description' => ($row['description'] ?? null) ?: null,
                'sort_order' => $sortOrder === null || $sortOrder === '' ? null : (int) $sortOrder,
                'status' => $row['status'] ?? FeeStructureItem::STATUS_ACTIVE,
            ];
        }

        return $normalised;
    }

    /**
     * @param  list<array>  $normalised
     */
    private function assertItemRules(array $normalised): void
    {
        if ($normalised === []) {
            throw ValidationException::withMessages([
                'items' => 'A fee structure requires at least one fee component.',
            ]);
        }

        $seen = [];

        foreach ($normalised as $index => $row) {
            if ($row['name'] === '') {
                throw ValidationException::withMessages([
                    "items.$index.name" => 'Each fee component requires a name.',
                ]);
            }

            if (mb_strlen($row['name']) > 255) {
                throw ValidationException::withMessages([
                    "items.$index.name" => 'The fee component name must not exceed 255 characters.',
                ]);
            }

            if ($row['amount'] === null) {
                throw ValidationException::withMessages([
                    "items.$index.amount" => 'Each fee component requires an amount.',
                ]);
            }

            // Money is never negative — a discount/fine belongs to its own
            // future module, not to a fee head.
            if ($row['amount'] < 0) {
                throw ValidationException::withMessages([
                    "items.$index.amount" => 'The amount must not be negative.',
                ]);
            }

            if ($row['amount'] > FeeStructureItem::MAX_AMOUNT) {
                throw ValidationException::withMessages([
                    "items.$index.amount" => 'The amount exceeds the maximum supported value.',
                ]);
            }

            if (! in_array($row['status'], FeeStructureItem::STATUSES, true)) {
                throw ValidationException::withMessages([
                    "items.$index.status" => 'The fee component status is invalid.',
                ]);
            }

            $key = mb_strtolower($row['name']);

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "items.$index.name" => "The fee component \"{$row['name']}\" is defined more than once in this fee structure.",
                ]);
            }

            $seen[$key] = true;
        }
    }

    private function assertUniqueActiveStructure(FeeStructure $structure): void
    {
        // The global college scope is bypassed deliberately: the guard states
        // its own college explicitly (college_id always comes from the tenant
        // context or the tenant-scoped model), so it also works when the service
        // is called outside an HTTP request.
        $duplicate = FeeStructure::withoutGlobalScopes()
            ->where('college_id', $structure->college_id)
            ->where('academic_year_id', $structure->academic_year_id)
            ->where('program_id', $structure->program_id)
            ->where('code', $structure->code)
            ->whereNull('deleted_at')
            ->when($structure->exists, fn ($query) => $query->whereKeyNot($structure->getKey()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'code' => self::DUPLICATE_CODE_MESSAGE,
            ]);
        }
    }

    private function assertTenant(FeeStructure $structure): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $structure->college_id === (int) $collegeId, 403);
    }
}
