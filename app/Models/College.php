<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class College extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'code', 'slug', 'email', 'phone', 'address', 'status'];

    public function campuses(): HasMany { return $this->hasMany(Campus::class); }
    public function academicYears(): HasMany { return $this->hasMany(AcademicYear::class); }
    public function users(): BelongsToMany { return $this->belongsToMany(User::class)->withTimestamps()->withPivot('is_default'); }
    public function roles(): HasMany { return $this->hasMany(Role::class); }
    public function settings(): HasMany { return $this->hasMany(InstitutionalSetting::class); }
}
