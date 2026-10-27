<?php

namespace Database\Seeders;

use App\Models\GeoLocation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Loads the Country → State → District → Block → Village directory used by the
 * registration wizard's dependent address selectors.
 *
 * ## What ships with the application
 *
 * `database/data/geo_locations.json` contains the **country and its states /
 * union territories** — publicly documented administrative units, with their
 * official names and ISO 3166-2 codes. It deliberately contains no districts,
 * blocks or villages: those have to come from the college's own administrative
 * source, and inventing them would put wrong place names in front of applicants.
 *
 * ## Loading a complete directory
 *
 * The source can be a single JSON file or a directory of `*.json` files (one per
 * state, for example). Point `GEO_LOCATION_SOURCE` at it and run
 *
 *     php artisan db:seed --class=GeoLocationSeeder
 *
 * Format (a tree; `children` at any level, levels may stop wherever the data
 * stops):
 *
 *     [
 *       {"name": "India", "code": "IN", "level": "country", "children": [
 *         {"name": "Madhya Pradesh", "code": "MP", "level": "state", "children": [
 *           {"name": "Bhopal", "level": "district", "children": [
 *             {"name": "Berasia", "level": "block", "children": [
 *               {"name": "Barkheda", "level": "village"}
 *             ]}
 *           ]}
 *         ]}
 *       ]}
 *     ]
 *
 * ## Safety
 *
 * * **Idempotent** — a row is matched on (parent, level, name), so re-running the
 *   seeder after adding one district creates only that district. No duplicates.
 * * **Additive only** — nothing is deleted, renamed or deactivated. Removing a
 *   place is a deliberate administrative action, not a seeding side effect.
 * * **Non-destructive default** — with no source file the seeder warns and
 *   changes nothing; the wizard then keeps its free-text address fallback.
 */
class GeoLocationSeeder extends Seeder
{
    /** Rows created, per level, in the current run (reported at the end). */
    private array $created = [];

    public function run(): void
    {
        $sources = $this->sources();

        if ($sources === []) {
            $this->command?->warn(
                'GeoLocationSeeder: no source found at '.$this->defaultSource().'. Nothing was seeded — the '
                .'registration form keeps using free-text address fields until a directory is loaded.'
            );

            return;
        }

        $trees = [];

        foreach ($sources as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);

            if (! is_array($decoded)) {
                $this->command?->error('GeoLocationSeeder: '.basename($path).' is not valid JSON. Skipped.');
                continue;
            }

            // Accept both a top-level list and a single root object.
            $trees[] = array_is_list($decoded) ? $decoded : [$decoded];
        }

        if ($trees === []) {
            return;
        }

        DB::transaction(function () use ($trees): void {
            foreach ($trees as $tree) {
                $this->insertLevel($tree, null);
            }
        });

        $summary = collect($this->created)
            ->map(fn (int $count, string $level): string => $count.' '.$level)
            ->implode(', ');

        $this->command?->info(
            'GeoLocationSeeder: '.($summary === '' ? 'nothing new — the directory was already up to date.' : 'created '.$summary.'.')
        );
    }

    /** @return array<int, string> */
    private function sources(): array
    {
        $configured = (string) env('GEO_LOCATION_SOURCE', '');

        if ($configured === '') {
            return is_file($this->defaultSource()) ? [$this->defaultSource()] : [];
        }

        $paths = [];

        foreach (preg_split('/[,;]/', $configured) ?: [] as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '') {
                continue;
            }

            if (is_dir($candidate)) {
                foreach (glob(rtrim($candidate, '/').'/*.json') ?: [] as $file) {
                    $paths[] = $file;
                }

                continue;
            }

            if (is_file($candidate)) {
                $paths[] = $candidate;
            }
        }

        return $paths;
    }

    private function defaultSource(): string
    {
        return database_path('data/geo_locations.json');
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function insertLevel(array $nodes, ?int $parentId): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node) || blank($node['name'] ?? null) || blank($node['level'] ?? null)) {
                continue;
            }

            $level = (string) $node['level'];

            if (! in_array($level, GeoLocation::LEVELS, true)) {
                $this->command?->warn('GeoLocationSeeder: unknown level "'.$level.'" skipped.');

                continue;
            }

            $model = GeoLocation::query()->firstOrCreate(
                [
                    'parent_id' => $parentId,
                    'level' => $level,
                    'name' => (string) $node['name'],
                ],
                [
                    'code' => $node['code'] ?? null,
                    'sort_order' => (int) ($node['sort_order'] ?? 0),
                    'is_active' => true,
                ],
            );

            if ($model->wasRecentlyCreated) {
                $this->created[$level] = ($this->created[$level] ?? 0) + 1;
            }

            if (! empty($node['children']) && is_array($node['children'])) {
                $this->insertLevel($node['children'], (int) $model->id);
            }
        }
    }
}
