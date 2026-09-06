<?php

namespace Tests\Feature\Departments;

use App\Models\{College, Permission, Role, User};
use Illuminate\Support\Str;

trait DepartmentTestHelpers
{
    private function makeCollege(string $code): College
    {
        return College::create(['name' => $code.' College', 'code' => $code, 'slug' => Str::slug($code).'-college', 'status' => 'active']);
    }

    private function makeUserWithPermissions(College $college, array $slugs, bool $superAdmin = false): User
    {
        $user = User::create([
            'name' => $college->code.($superAdmin ? ' Super' : ' Staff'),
            'email' => strtolower($college->code).'-'.($superAdmin ? 'super' : 'staff').'-'.Str::random(6).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->colleges()->attach($college->id, ['is_default' => true]);

        $role = Role::create(['college_id' => $superAdmin ? null : $college->id, 'name' => $college->code.' Dept Role', 'slug' => 'dept-role-'.strtolower($college->code).'-'.Str::random(4), 'is_system' => false, 'is_active' => true]);
        if ($slugs) {
            $role->permissions()->sync(Permission::whereIn('slug', $slugs)->pluck('id')->all());
        }
        $user->roles()->attach($role->id, ['college_id' => $superAdmin ? null : $college->id]);

        return $user;
    }

    private function superAdminUser(): User
    {
        return User::create(['name' => 'Platform Super', 'email' => 'super-'.Str::random(6).'@example.test', 'password' => 'password', 'is_active' => true]);
    }

    private function asCollege(College $college, User $user): static
    {
        return $this->actingAs($user)->withSession(['active_college_id' => $college->id]);
    }
}
