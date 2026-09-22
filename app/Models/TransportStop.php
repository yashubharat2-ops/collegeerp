<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransportStop extends Model
{
    use BelongsToCollege, SoftDeletes;

    public const FIELDS = ['name' => 'text', 'code' => 'text', 'sequence' => 'number', 'pickup_time' => 'time', 'drop_time' => 'time', 'landmark' => 'text', 'status' => 'select'];

    public const STATUSES = ['active', 'inactive'];

    protected $fillable = ['name', 'code', 'sequence', 'pickup_time', 'drop_time', 'landmark', 'status'];

    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $value));
    }

    public function route(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'route_id');
    }
}
