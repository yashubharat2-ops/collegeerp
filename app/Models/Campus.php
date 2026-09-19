<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campus extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    protected $fillable = [
        'college_id',
        'name',
        'code',
        'short_name',
        'address',
        'city',
        'state',
        'pincode',
        'phone',
        'email',
        'status',
        'description',
        'created_by',
        'updated_by',
    ];

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
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
