<?php

namespace Database\Seeders;

use App\Models\{College, Permission, Role, User};
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $college = College::firstOrCreate(['code' => 'DEMO'], ['name' => 'Demo College', 'slug' => 'demo-college', 'status' => 'active']);
        $permissions = collect(['dashboard.view', 'colleges.view', 'colleges.update', 'campuses.view', 'campuses.create', 'campuses.update', 'campuses.delete', 'academic-years.view', 'academic-years.create', 'academic-years.update', 'settings.view', 'settings.update', 'roles.view', 'roles.update', 'permissions.view'])->mapWithKeys(fn ($slug) => [$slug => Permission::firstOrCreate(['slug' => $slug], ['name' => Str::headline($slug), 'module' => Str::before($slug, '.'), 'action' => Str::after($slug, '.')])]);
        $super = Role::firstOrCreate(['college_id' => null, 'slug' => 'super-admin'], ['name' => 'Super Admin', 'is_system' => true]);
        $admin = Role::firstOrCreate(['college_id' => $college->id, 'slug' => 'college-admin'], ['name' => 'College Admin', 'is_system' => true]);
        $permissionIds = $permissions->values()->map->getKey()->all();
        $super->permissions()->sync($permissionIds);
        $admin->permissions()->sync($permissionIds);
        $user = User::firstOrCreate(['email' => 'test@example.com'], ['name' => 'Test User', 'password' => 'password', 'is_active' => true]);
        $user->colleges()->syncWithoutDetaching([$college->id => ['is_default' => true]]);
        $user->roles()->syncWithoutDetaching([$super->id => ['college_id' => null], $admin->id => ['college_id' => $college->id]]);
    }
}
