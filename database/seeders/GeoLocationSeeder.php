<?php

namespace Database\Seeders;

use App\Models\GeoLocation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Loads the official Country → State → District → Block → Village directory used
 * by the registration wizard's dependent address selectors.
 *
 * This seeder deliberately contains NO place names. Inventing administrative
 * geography — or hard-coding one country's states — would put wrong data in
 * front of every applicant and would have to be un-done college by college.
 *
 * Usage: provide a JSON file with an explicit hierarchy and run
 *
 *     php artisan db:seed --class=GeoLocationSeeder
 *
 * The file path comes from `GEO_LOCATION_SOURCE` (default
 * `database/data/geo_locations.json`). Format:
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
 * Levels may stop at any depth (a college that only needs state/district leaves
 * the deeper levels out). Re-running is idempotent: rows are matched on
 * (parent, level, name).
 */
class GeoLocationSeeder extends Seeder
{
    public function run(): void
    {
        $path = (string) env('GEO_LOCATION_SOURCE', database_path('data/geo_locations.json'));

        if (! is_file($path)) {
            $this->command?->warn(
                'GeoLocationSeeder: no source file at '.$path.'. Nothing was seeded — the registration '
                .'form keeps using free-text address fields until a directory is loaded.'
            );

            return;
        }

        $tree = json_decode((string) file_get_contents($path), true);

        if (! is_array($tree)) {
            $this->command?->error('GeoLocationSeeder: the source file is not valid JSON. Nothing was seeded.');

            return;
        }

        DB::transaction(function () use ($tree): void {
            $this->insertLevel($tree, null);
        });
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

            if (! in_array($node['level'], GeoLocation::LEVELS, true)) {
                continue;
            }

            $model = GeoLocation::query()->firstOrCreate(
                [
                    'parent_id' => $parentId,
                    'level' => $node['level'],
                    'name' => (string) $node['name'],
                ],
                [
                    'code' => $node['code'] ?? null,
                    'sort_order' => (int) ($node['sort_order'] ?? 0),
                    'is_active' => true,
                ],
            );

            if (! empty($node['children']) && is_array($node['children'])) {
                $this->insertLevel($node['children'], (int) $model->id);
            }
        }
    }
}
