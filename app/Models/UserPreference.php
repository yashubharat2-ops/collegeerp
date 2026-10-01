<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One interface preference owned by one user (see UserPreferenceService for the
 * small allowlist of keys the application actually consumes).
 */
class UserPreference extends Model
{
    protected $fillable = ['user_id', 'key', 'value', 'type'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
