<?php

namespace Tests\Feature\HR;

use App\Models\{College, Permission, Role, User};
use Illuminate\Support\Str;

trait HRTestHelpers
{
    private function makeCollege(string $code): College
    {
        return College::create([
            'name' => $code.' College',
            'code' => $code,
            'slug' => Str::slug($code).'-college',
            'status' => 'active',
        ]);
    }

    private function makeUserWithPermissions(College $college, array $permissions): User
    {
        $user = User::create([
            'name' => $college->code.' HR User',
            'email' => strtolower($college->code).'-hr-'.Str::random(8).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->colleges()->attach($college->id, ['is_default' => true]);

        $role = Role::create([
            'college_id' => $college->id,
            'name' => $college->code.' HR Role',
            'slug' => 'hr-role-'.strtolower($college->code).'-'.Str::random(8),
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::whereIn('slug', $permissions)->pluck('id')->all());
        $user->roles()->attach($role->id, ['college_id' => $college->id]);

        return $user;
    }

    private function asCollege(College $college, User $user): static
    {
        return $this->actingAs($user)->withSession(['active_college_id' => $college->id]);
    }
}
