<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * College-owned employment designation master.
 *
 * Designations are intentionally separate from Department (an organisational
 * unit) and from Faculty's legacy display string. Faculty remains the single
 * staff/employee record; `designation_id` provides the normalized HR link.
 */
class Designation extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const STATUSES = ['active', 'inactive'];

    protected $fillable = [
        'college_id',
        'name',
        'code',
        'description',
        'status',
        'created_by',
        'updated_by',
    ];

    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $value));
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Faculty::class, 'designation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
