<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reference data for the Country → State → District → Block → Village chain.
 *
 * Intentionally NOT college-scoped: a state is not a per-college fact, and
 * duplicating the directory per college is how reference data drifts.
 *
 * Ships empty. Nothing in this application invents region names; an
 * administrator loads the official directory (GeoLocationSeeder) and the
 * registration form switches from free-text inputs to dependent selects.
 */
class GeoLocation extends Model
{
    public const LEVEL_COUNTRY = 'country';
    public const LEVEL_STATE = 'state';
    public const LEVEL_DISTRICT = 'district';
    public const LEVEL_BLOCK = 'block';
    public const LEVEL_VILLAGE = 'village';

    /** The dependency chain, in order. */
    public const LEVELS = [
        self::LEVEL_COUNTRY,
        self::LEVEL_STATE,
        self::LEVEL_DISTRICT,
        self::LEVEL_BLOCK,
        self::LEVEL_VILLAGE,
    ];

    public const LEVEL_LABELS = [
        self::LEVEL_COUNTRY => 'Country',
        self::LEVEL_STATE => 'State',
        self::LEVEL_DISTRICT => 'District',
        self::LEVEL_BLOCK => 'Block / Tehsil',
        self::LEVEL_VILLAGE => 'Village / City',
    ];

    protected $fillable = ['parent_id', 'level', 'name', 'code', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }
}
