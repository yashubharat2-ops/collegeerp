<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    public const SUPER_ADMIN_SLUG = 'super-admin';
    use HasFactory;
    protected $fillable = ['college_id', 'name', 'slug', 'description', 'is_system', 'is_active'];
    protected function casts(): array { return ['is_system' => 'boolean', 'is_active' => 'boolean']; }
    public function college() { return $this->belongsTo(College::class); }
    public function users(): BelongsToMany { return $this->belongsToMany(User::class)->withPivot('college_id')->withTimestamps(); }
    public function permissions(): BelongsToMany { return $this->belongsToMany(Permission::class); }
}
