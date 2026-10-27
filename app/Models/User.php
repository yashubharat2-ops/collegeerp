<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'is_active', 'last_login_at', 'last_login_ip'];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'is_active' => 'boolean', 'last_login_at' => 'datetime'];
    }

    public function colleges(): BelongsToMany
    {
        return $this->belongsToMany(College::class, 'user_college')->withPivot('is_default')->withTimestamps();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot('college_id')->withTimestamps();
    }

    public function hasPermission(string $permission, ?int $collegeId = null): bool
    {
        if (! $this->is_active) return false;
        if ($this->isSuperAdmin()) {
            return Permission::query()
                ->where('slug', $permission)
                ->where('is_active', true)
                ->exists();
        }
        $collegeId ??= app(\App\Support\Tenancy\TenantContext::class)->id();
        if (! $collegeId || ! $this->colleges()->whereKey($collegeId)->exists()) return false;
        return $this->roles()->where('roles.is_active', true)
            ->where(function ($query) use ($collegeId) { $query->where('role_user.college_id', $collegeId); })
            ->whereHas('permissions', fn ($query) => $query->where('permissions.slug', $permission)->where('permissions.is_active', true))
            ->exists();
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_active && $this->roles()->where('roles.slug', Role::SUPER_ADMIN_SLUG)->whereNull('role_user.college_id')->exists();
    }

    /**
     * Whether this user holds the reserved College Admin role of ONE college.
     *
     * The role is matched by its reserved slug and by the college the role was
     * attached under (`role_user.college_id`), so being an administrator of
     * college A never makes the user an administrator of college B. Membership
     * of the college is required too, so a stale role row cannot grant anything.
     */
    public function isCollegeAdmin(?int $collegeId = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $collegeId ??= app(\App\Support\Tenancy\TenantContext::class)->id();

        if (! $collegeId || ! $this->colleges()->whereKey($collegeId)->exists()) {
            return false;
        }

        return $this->roles()
            ->where('roles.is_active', true)
            ->where('roles.slug', Role::COLLEGE_ADMIN_SLUG)
            ->where('role_user.college_id', $collegeId)
            ->exists();
    }

    /**
     * A trusted authority: the platform Super Admin or the College Admin of the
     * college in question.
     *
     * Trusted authorities are the two reserved system roles. They may finalize
     * their own work directly and may decide their own approval requests; every
     * other user follows the central approval workflow whenever an action
     * requires it, and can never self-approve.
     */
    public function isTrustedAuthority(?int $collegeId = null): bool
    {
        return $this->isSuperAdmin() || $this->isCollegeAdmin($collegeId);
    }
}
