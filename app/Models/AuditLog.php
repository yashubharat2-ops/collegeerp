<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected function casts(): array { return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function college(): BelongsTo { return $this->belongsTo(College::class); }
    public function subject(): MorphTo { return $this->morphTo(); }
    protected static function booted(): void
    {
        static::deleting(fn () => false);
        static::updating(fn () => false);
    }
}
