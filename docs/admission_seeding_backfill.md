# Admission Permissions — Safe Seeding / Backfill

**Context:** Fresh tests call `$this->seed()` in `TestCase::setUp()`, so they always have latest permissions. Existing local databases may be stale after new permissions are added.

**New permissions in this milestone:**

- admission_dashboard.view
- admission_documents.view, create, update, delete, verify
- admission_document_types.view, create, update, delete
- admission_merit.view, create, update, delete, publish
- admissions.view, create, update, delete
- admission_reports.view

**Safe backfill (DO NOT use migrate:fresh):**

`DatabaseSeeder` is idempotent: uses `firstOrCreate` for permissions and `sync` for system roles. Re-running it will not duplicate data or delete existing records.

```bash
# 1. Ensure latest code is pulled
git pull origin main

# 2. Run migrations (non-destructive)
php artisan migrate --force

# 3. Backfill permissions and roles (safe)
php artisan db:seed --force

# 4. Verify
php artisan tinker --execute="echo \App\Models\Permission::where('slug','like','admission_%')->pluck('slug')->implode(', ');"
```

For custom roles (non system), attach new permissions manually:

```bash
php artisan tinker
```

```php
$collegeId = 1; // your college id
$role = \App\Models\Role::where('college_id', $collegeId)->where('slug', 'admission-officer')->first();
$perms = \App\Models\Permission::whereIn('slug', [
    'admission_documents.view',
    'admission_documents.verify',
    'admission_merit.view',
    'admissions.view',
    'admission_reports.view',
])->pluck('id');
$role->permissions()->syncWithoutDetaching($perms);
```

**Why not migrate:fresh?**

- Destroys all data (colleges, applicants, applications, documents, admissions).
- Breaks audit history.
- Not safe for staging/production.

**CI / Test:**

Tests use `RefreshDatabase` trait + `seed()` in `TestCase`, so no manual backfill needed for test runs. Just run:

```bash
php artisan test
```

