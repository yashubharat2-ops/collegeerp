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
     * The country pre-selected for a new address: `IN` when the directory has
     * it (the application's own country), otherwise the first root country.
     *
     * Returning an id rather than writing the text keeps the default honest — a
     * college whose directory only holds another country gets that country, and
     * one with an empty directory gets null and the free-text fallback.
     */
    public function defaultCountryId(): ?int
    {
        $country = GeoLocation::query()
            ->where('level', GeoLocation::LEVEL_COUNTRY)
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->orderByRaw("CASE WHEN UPPER(COALESCE(code, '')) = 'IN' THEN 0 WHEN name = 'India' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->first(['id']);

        return $country !== null ? (int) $country->id : null;
    }

    /**
     * The ids to render in the five address selects.
     *
     * The draft is the source of truth, but a draft can hold a stale or partial
     * chain (a saved state with no country because the level did not exist yet).
     * The missing ancestors are filled from the directory, and the country falls
     * back to {@see defaultCountryId()}, which is what makes the state list
     * populate on a resumed draft instead of showing an empty select.
     *
     * @param  array<string, mixed>  $merged
     * @return array{country_id: int|null, state_id: int|null, district_id: int|null, block_id: int|null, village_id: int|null}
     */
    public function selection(array $merged): array
    {
        $keys = ['country_id', 'state_id', 'district_id', 'block_id', 'village_id'];
        $selection = [];

        foreach ($keys as $key) {
            $value = $merged[$key] ?? null;
            $selection[$key] = $value === null || $value === '' ? null : (int) $value;
        }

        // Walk down from the deepest id that is present and fill the ancestors.
        $deepest = null;

        foreach (['village_id', 'block_id', 'district_id', 'state_id'] as $key) {
            if ($selection[$key] !== null) {
                $deepest = $selection[$key];
                break;
            }
        }

        if ($deepest !== null) {
            $chain = $this->ancestors($deepest);

            $columns = [
                GeoLocation::LEVEL_COUNTRY => 'country_id',
                GeoLocation::LEVEL_STATE => 'state_id',
                GeoLocation::LEVEL_DISTRICT => 'district_id',
                GeoLocation::LEVEL_BLOCK => 'block_id',
                GeoLocation::LEVEL_VILLAGE => 'village_id',
            ];

            foreach ($chain as $node) {
                $key = $columns[$node['level']] ?? null;

                if ($key !== null) {
                    $selection[$key] = $node['id'];
                }
            }
        }

        if ($selection['country_id'] === null) {
            $selection['country_id'] = $this->defaultCountryId();
        }

        return $selection;
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
