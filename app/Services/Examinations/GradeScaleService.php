<?php

namespace App\Services\Examinations;

use App\Models\College;
use App\Models\GradeScale;
use App\Models\GradeScaleItem;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * GradeScaleService — CRUD + structural validation for the configurable
 * Grade / Pass-Fail rules (Examinations Phase 3).
 *
 * The service owns NO grading knowledge of its own: every boundary lives in
 * GradeScaleItem rows created by the college. What it does guarantee is that a
 * saved configuration is structurally sound:
 *
 *   - the scale and every item belong to the ACTIVE college
 *   - min_percentage <= max_percentage
 *   - percentages sit inside 0–100
 *   - no duplicate active grade definitions inside one scale
 *   - no overlapping ranges inside one scale
 *   - deterministic sort_order
 *
 * Tenant safety: college_id is always taken from the authenticated tenant
 * context; a browser-supplied college_id never reaches the database.
 */
class GradeScaleService
{
    private const AUDITED = ['name', 'code', 'status', 'description'];

    private const ITEM_AUDITED = [
        'grade',
        'min_percentage',
        'max_percentage',
        'grade_point',
        'description',
        'sort_order',
        'status',
    ];

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array{name: string, code: string, status: string, description?: string|null, items?: array<int, array>}  $data
     */
    public function create(College $college, array $data, User $actor): GradeScale
    {
        return DB::transaction(function () use ($college, $data, $actor): GradeScale {
            $scale = new GradeScale([
                // college_id is stamped here from the tenant context — never
                // copied from request data.
                'college_id' => $college->getKey(),
                'name' => $data['name'],
                'code' => $data['code'],
                'status' => $data['status'],
                'description' => $data['description'] ?? null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->assertUniqueActiveCode($scale);

            try {
                $scale->save();
            } catch (QueryException) {
                // Partial unique index (SQLite/Postgres) rejected a racing
                // insert; MySQL relies on the guard above.
                throw ValidationException::withMessages([
                    'code' => 'A grade scale with this code already exists for the active college.',
                ]);
            }

            $this->syncItems($scale, $data['items'] ?? [], $actor, $college);

            $this->audit->record('grade_scales.created', $scale, [], $scale->only(self::AUDITED));

            return $scale->refresh();
        });
    }

    /**
     * @param  array{name?: string, code?: string, status?: string, description?: string|null, items?: array<int, array>}  $data
     */
    public function update(GradeScale $scale, array $data, User $actor): GradeScale
    {
        $this->assertTenant($scale);

        return DB::transaction(function () use ($scale, $data, $actor): GradeScale {
            $old = $scale->only(self::AUDITED);

            foreach (['name', 'code', 'status', 'description'] as $field) {
                if (array_key_exists($field, $data)) {
                    $scale->{$field} = $data[$field];
                }
            }
            $scale->updated_by = $actor->getKey();

            $this->assertUniqueActiveCode($scale);

            try {
                $scale->save();
            } catch (QueryException) {
                throw ValidationException::withMessages([
                    'code' => 'A grade scale with this code already exists for the active college.',
                ]);
            }

            if (array_key_exists('items', $data)) {
                $this->syncItems($scale, $data['items'] ?? [], $actor);
            }

            $this->audit->record('grade_scales.updated', $scale, $old, $scale->only(self::AUDITED));

            return $scale->refresh();
        });
    }

    public function delete(GradeScale $scale, User $actor): void
    {
        $this->assertTenant($scale);

        DB::transaction(function () use ($scale, $actor): void {
            $snapshot = $scale->only(self::AUDITED);

            // Items follow their parent scale.
            $scale->allItems()->delete();
            $scale->delete();

            $this->audit->record('grade_scales.deleted', $scale, $snapshot, []);
        });
    }

    /**
     * Replace the scale's grade bands with the submitted set, fully validated.
     *
     * @param  array<int, array>  $items
     */
    private function syncItems(GradeScale $scale, array $items, User $actor, ?College $college = null): void
    {
        $collegeId = $college?->getKey() ?? $scale->college_id;
        $normalised = $this->normaliseItems($items);

        $this->assertNoDuplicateGrades($normalised);
        $this->assertNoOverlaps($normalised);

        $keptIds = [];

        foreach ($normalised as $index => $row) {
            $attributes = [
                'college_id' => $collegeId,
                'grade' => $row['grade'],
                'min_percentage' => $row['min_percentage'],
                'max_percentage' => $row['max_percentage'],
                'grade_point' => $row['grade_point'],
                'description' => $row['description'],
                // Deterministic ordering: explicit sort_order when given,
                // otherwise the order the bands were submitted in.
                'sort_order' => $row['sort_order'] ?? $index,
                'status' => $row['status'],
            ];

            $item = $row['id'] !== null
                ? $scale->allItems()->whereKey($row['id'])->first()
                : null;

            if ($item) {
                $old = $item->only(self::ITEM_AUDITED);
                $item->fill($attributes)->save();
                $this->audit->record('grade_scale_items.updated', $item, $old, $item->only(self::ITEM_AUDITED));
            } else {
                $item = $scale->allItems()->create($attributes);
                $this->audit->record('grade_scale_items.created', $item, [], $item->only(self::ITEM_AUDITED));
            }

            $keptIds[] = $item->getKey();
        }

        // Bands removed from the submitted set no longer belong to the scale.
        $scale->allItems()->whereNotIn('id', $keptIds)->delete();
    }

    /**
     * Normalise raw grade-band rows into a strictly typed, deterministic list.
     *
     * @param  array<int, array>  $items
     * @return list<array{id: int|null, grade: string, min_percentage: float, max_percentage: float, grade_point: float|null, description: string|null, sort_order: int|null, status: string}>
     */
    private function normaliseItems(array $items): array
    {
        $normalised = [];

        foreach (array_values($items) as $row) {
            $min = $row['min_percentage'] ?? null;
            $max = $row['max_percentage'] ?? null;
            $sortOrder = $row['sort_order'] ?? null;

            $normalised[] = [
                'id' => isset($row['id']) && $row['id'] !== '' ? (int) $row['id'] : null,
                'grade' => trim((string) ($row['grade'] ?? '')),
                'min_percentage' => $min === null || $min === '' ? null : round((float) $min, 3),
                'max_percentage' => $max === null || $max === '' ? null : round((float) $max, 3),
                'grade_point' => ($row['grade_point'] ?? null) === '' || ($row['grade_point'] ?? null) === null
                    ? null
                    : round((float) $row['grade_point'], 2),
                'description' => ($row['description'] ?? null) ?: null,
                'sort_order' => $sortOrder === null || $sortOrder === '' ? null : (int) $sortOrder,
                'status' => $row['status'] ?? GradeScaleItem::STATUS_ACTIVE,
            ];
        }

        return $normalised;
    }

    /**
     * Validate a set of grade bands. Throws the first structural problem found
     * so the UI can point at the offending row.
     *
     * Called by the Form Requests (write path) and reused by the calculation
     * engine (read path) so a saved-but-broken configuration can never publish.
     *
     * @param  array<int, array>  $items  Raw rows (before normalisation).
     */
    public function validateItems(array $items): void
    {
        $normalised = $this->normaliseItems($items);

        $this->assertNoDuplicateGrades($normalised);
        $this->assertNoOverlaps($normalised);
    }

    /**
     * @param  list<array>  $normalised
     */
    private function assertNoDuplicateGrades(array $normalised): void
    {
        $seen = [];

        foreach ($normalised as $index => $row) {
            if ($row['grade'] === '') {
                throw ValidationException::withMessages([
                    "items.$index.grade" => 'Each grade band requires a grade.',
                ]);
            }

            if ($row['min_percentage'] === null || $row['max_percentage'] === null) {
                throw ValidationException::withMessages([
                    "items.$index.min_percentage" => 'Each grade band requires a minimum and maximum percentage.',
                ]);
            }

            if ($row['min_percentage'] < 0 || $row['max_percentage'] > 100) {
                throw ValidationException::withMessages([
                    "items.$index.min_percentage" => 'Percentages must be between 0 and 100.',
                ]);
            }

            if ($row['min_percentage'] > $row['max_percentage']) {
                throw ValidationException::withMessages([
                    "items.$index.min_percentage" => 'The minimum percentage must be less than or equal to the maximum percentage.',
                ]);
            }

            $key = mb_strtolower($row['grade']);

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "items.$index.grade" => "The grade \"{$row['grade']}\" is defined more than once in this grade scale.",
                ]);
            }

            $seen[$key] = true;
        }
    }

    /**
     * Ranges inside one scale must never overlap.
     *
     * @param  list<array>  $normalised
     */
    private function assertNoOverlaps(array $normalised): void
    {
        $ordered = $normalised;
        usort($ordered, fn (array $a, array $b): int => [$a['min_percentage'], $a['max_percentage']]
            <=> [$b['min_percentage'], $b['max_percentage']]);

        $previous = null;

        foreach ($ordered as $row) {
            if ($previous !== null && $row['min_percentage'] < $previous['max_percentage']) {
                throw ValidationException::withMessages([
                    'items' => "The grade band \"{$row['grade']}\" overlaps the band \"{$previous['grade']}\". Percentage ranges must not overlap.",
                ]);
            }

            $previous = $row;
        }
    }

    /**
     * Whether a persisted scale can actually be used by the rule engine.
     *
     * Mirrors the write-path validation so a configuration that was edited
     * directly in the database (or left half-configured) cannot be used to
     * publish results.
     */
    public function configurationIsUsable(GradeScale $scale): bool
    {
        $items = $scale->items()->where('status', GradeScaleItem::STATUS_ACTIVE)->get();

        if ($items->isEmpty()) {
            return false;
        }

        try {
            $this->assertNoDuplicateGrades(
                $this->normaliseItems($items->map->only([
                    'grade', 'min_percentage', 'max_percentage', 'grade_point',
                    'description', 'sort_order', 'status',
                ])->all())
            );
            $this->assertNoOverlaps(
                $this->normaliseItems($items->map->only([
                    'grade', 'min_percentage', 'max_percentage', 'grade_point',
                    'description', 'sort_order', 'status',
                ])->all())
            );
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    private function assertUniqueActiveCode(GradeScale $scale): void
    {
        $duplicate = GradeScale::query()
            ->where('college_id', $scale->college_id)
            ->where('code', $scale->code)
            ->when($scale->exists, fn ($query) => $query->whereKeyNot($scale->getKey()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'code' => 'A grade scale with this code already exists for the active college.',
            ]);
        }
    }

    private function assertTenant(GradeScale $scale): void
    {
        $collegeId = app(\App\Support\Tenancy\TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $scale->college_id === (int) $collegeId, 403);
    }
}
