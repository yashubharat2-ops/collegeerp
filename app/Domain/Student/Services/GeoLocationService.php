<?php

namespace App\Domain\Student\Services;

use App\Models\GeoLocation;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The Country → State → District → Block → Village directory used by the
 * permanent-address block of the registration wizard.
 *
 * IMPORTANT — this service never invents geography. The `geo_locations` table
 * ships EMPTY and no state/district/village names are hard-coded anywhere in
 * this application. Until a college (or the platform operator) loads an
 * official directory, {@see hasLevel()} returns false and the wizard renders
 * free-text inputs for that level, so the form is usable immediately and turns
 * into a dependent dropdown the moment real data exists.
 *
 * The chain is enforced server-side, not just in the UI: {@see resolveChain()}
 * walks the parents of the chosen village and refuses a combination whose
 * parent links do not match the submitted ids.
 */
class GeoLocationService
{
    /** Children of a node (or the roots of a level when $parentId is null). */
    public function children(?int $parentId, string $level): Collection
    {
        if (! in_array($level, GeoLocation::LEVELS, true)) {
            return collect();
        }

        return GeoLocation::query()
            ->where('level', $level)
            ->where('is_active', true)
            ->when(
                $parentId === null,
                fn ($query) => $query->whereNull('parent_id'),
                fn ($query) => $query->where('parent_id', $parentId),
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'level', 'name']);
    }

    /** Options for a select, shaped for a Blade `@foreach`. */
    public function options(string $level, ?int $parentId = null): Collection
    {
        return $this->children($parentId, $level);
    }

    /**
     * Does the directory hold data for this level? This is what the view asks
     * before deciding between a dependent <select> and a free-text input.
     */
    public function hasLevel(string $level): bool
    {
        return GeoLocation::query()
            ->where('level', $level)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Resolve a chosen village and verify every ancestor matches the submitted
     * ids. Returns the names for the stored text columns.
     *
     * A mismatch is a validation error, not a silent correction: the browser
     * must never be able to attach a village from another block.
     *
     * @param  array<string, int|null>  $ids  keys: country_id, state_id, district_id, block_id, village_id
     * @return array{country: ?string, state: ?string, district: ?string, block: ?string, village: ?string}
     */
    public function resolveChain(array $ids): array
    {
        $result = ['country' => null, 'state' => null, 'district' => null, 'block' => null, 'village' => null];

        $chain = [
            'country_id' => GeoLocation::LEVEL_COUNTRY,
            'state_id' => GeoLocation::LEVEL_STATE,
            'district_id' => GeoLocation::LEVEL_DISTRICT,
            'block_id' => GeoLocation::LEVEL_BLOCK,
            'village_id' => GeoLocation::LEVEL_VILLAGE,
        ];

        $parent = null;

        foreach ($chain as $key => $level) {
            $id = $ids[$key] ?? null;

            if ($id === null || $id === '') {
                // A gap in the middle of the chain is fine (the directory may
                // stop at district). Only a missing parent for a chosen child
                // is impossible, because that is checked below.
                $parent = null;
                continue;
            }

            $node = GeoLocation::query()->where('level', $level)->whereKey((int) $id)->first();

            if ($node === null) {
                throw ValidationException::withMessages([
                    $key => 'Choose a valid '.GeoLocation::LEVEL_LABELS[$level].' from the list.',
                ]);
            }

            if ((int) $node->parent_id !== (int) $parent) {
                throw ValidationException::withMessages([
                    $key => 'That '.GeoLocation::LEVEL_LABELS[$level].' does not belong to the selected parent.',
                ]);
            }

            $result[['country_id' => 'country', 'state_id' => 'state', 'district_id' => 'district', 'block_id' => 'block', 'village_id' => 'village'][$key]] = $node->name;
            $parent = $node->id;
        }

        return $result;
    }

    /**
     * The parent chain of a node, root first. Used by the edit/resume screen to
     * pre-select the dependent dropdowns without a round trip per level.
     *
     * @return array<int, array{level: string, id: int, name: string, parent_id: int|null}>
     */
    public function ancestors(int $id): array
    {
        $chain = [];
        $node = GeoLocation::query()->find($id);
        $guard = 0;

        while ($node !== null && $guard < 10) {
            array_unshift($chain, [
                'level' => $node->level,
                'id' => (int) $node->id,
                'name' => $node->name,
                'parent_id' => $node->parent_id !== null ? (int) $node->parent_id : null,
            ]);
            $node = $node->parent_id !== null ? GeoLocation::query()->find($node->parent_id) : null;
            $guard++;
        }

        return $chain;
    }
}
