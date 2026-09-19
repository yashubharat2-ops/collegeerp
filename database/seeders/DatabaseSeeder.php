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
        $permissions = collect([
            'dashboard.view',
            'colleges.view', 'colleges.update',
            'campuses.view', 'campuses.create', 'campuses.update', 'campuses.delete',
            'departments.view', 'departments.create', 'departments.update', 'departments.delete',
            'academic-years.view', 'academic-years.create', 'academic-years.update',
            'programs.view', 'programs.create', 'programs.update', 'programs.delete',
            'admission_applicants.view', 'admission_applicants.create', 'admission_applicants.update', 'admission_applicants.delete',
            'admission_enquiries.view', 'admission_enquiries.create', 'admission_enquiries.update', 'admission_enquiries.delete',
            'admission_applications.view', 'admission_applications.create', 'admission_applications.update', 'admission_applications.delete',
            'admission_dashboard.view',
            'admission_documents.view', 'admission_documents.create', 'admission_documents.update', 'admission_documents.delete', 'admission_documents.verify',
            'admission_document_types.view', 'admission_document_types.create', 'admission_document_types.update', 'admission_document_types.delete',
            'admission_merit.view', 'admission_merit.create', 'admission_merit.update', 'admission_merit.delete', 'admission_merit.publish',
            'admissions.view', 'admissions.create', 'admissions.update', 'admissions.delete',
            'students.view', 'students.create', 'students.update', 'students.delete',
            'student_enrollments.view', 'student_enrollments.create', 'student_enrollments.update', 'student_enrollments.delete',
            'admission_reports.view',
            'settings.view', 'settings.update',
            'roles.view', 'roles.update',
            'permissions.view',
        ])->mapWithKeys(fn ($slug) => [$slug => Permission::firstOrCreate(['slug' => $slug], ['name' => Str::headline($slug), 'module' => Str::before($slug, '.'), 'action' => Str::after($slug, '.')])]);
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
