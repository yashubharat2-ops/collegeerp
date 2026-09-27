<?php

namespace Database\Seeders;

use App\Models\{College, Permission, Role};
use App\Services\Certificates\CertificateCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CertificateManagementSeeder extends Seeder
{
    public const PERMISSIONS = [
        'certificates.view', 'certificates.request', 'certificates.generate', 'certificates.issue',
        'certificates.verify', 'certificate_types.manage', 'certificate_templates.manage', 'certificate_reports.view',
    ];

    public function run(): void
    {
        $ids = [];
        foreach (self::PERMISSIONS as $slug) {
            $ids[] = Permission::firstOrCreate(['slug' => $slug], [
                'name' => Str::headline($slug), 'module' => Str::before($slug, '.'), 'action' => Str::after($slug, '.'),
            ])->id;
        }
        Role::whereIn('slug', ['college-admin', 'super-admin'])->each(fn ($role) => $role->permissions()->syncWithoutDetaching($ids));
        College::each(fn ($college) => app(CertificateCatalog::class)->provision($college->id));
    }
}
