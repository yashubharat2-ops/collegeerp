<?php

namespace Tests\Feature\Administration;

use App\Models\College;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

trait AdministrationTestHelpers
{
    private function college(string $code = 'ADMIN'): College
    {
        return College::create(['name' => $code.' Institution', 'code' => $code, 'slug' => Str::slug($code).'-'.Str::lower(Str::random(8)), 'status' => 'active']);
    }

    private function role(College $college, array $permissions = [], array $attributes = []): Role
    {
        $role = Role::create([
            'college_id' => $college->id, 'name' => 'College '.Str::random(8), 'slug' => 'custom-'.Str::lower(Str::random(10)),
            'is_active' => true, 'is_system' => false, ...$attributes,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id')->all());

        return $role;
    }

    private function actor(College $college, array $permissions = [], array $attributes = []): User
    {
        $user = User::factory()->create(['is_active' => true, ...$attributes]);
        $user->colleges()->attach($college->id, ['is_default' => true]);
        if ($permissions !== []) {
            $role = $this->role($college, $permissions);
            $user->roles()->attach($role->id, ['college_id' => $college->id]);
        }

        return $user;
    }

    private function super(College $college): User
    {
        $user = User::query()->where('email', 'test@example.com')->firstOrFail();
        if (! $user->colleges()->whereKey($college->id)->exists()) {
            $user->colleges()->attach($college->id, ['is_default' => false]);
        }

        return $user;
    }

    private function asCollege(College $college, User $user): static
    {
        app(TenantContext::class)->clear();

        return $this->actingAs($user)->withSession(['active_college_id' => $college->id]);
    }

    private function profilePayload(array $overrides = []): array
    {
        return ['name' => 'Configured Institution', 'email' => 'office@example.org', 'phone' => '+91 555 1000', 'address' => 'Institution address', 'short_name' => 'Configured ERP', ...$overrides];
    }
}
