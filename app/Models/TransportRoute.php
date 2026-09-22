<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransportRoute extends Model
{
    use BelongsToCollege, SoftDeletes;

    public const FIELDS = ['name' => 'text', 'code' => 'text', 'description' => 'textarea', 'status' => 'select'];

    public const STATUSES = ['active', 'inactive'];

    protected $fillable = ['name', 'code', 'description', 'status'];

    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $value));
    }

    public function stops(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TransportStop::class, 'route_id')->orderBy('sequence')->orderBy('id');
    }
}
