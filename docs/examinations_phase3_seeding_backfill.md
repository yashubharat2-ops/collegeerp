# Examinations Phase 3 Permissions — Safe Seeding / Backfill

**Context:** Fresh test runs call `$this->seed()` in `TestCase::setUp()`, so they always have the latest permissions. Existing local databases seeded **before** the Phase 3 permission slugs landed (merged in PR #23) keep working, but every Phase 3 policy check returns `false` and the sidebar drops the Results / Result Calculation / Grade / Pass-Fail / Result Publishing menu items — with no error anywhere. The fix is a re-seed, exactly as documented for the Admissions milestone (`docs/admission_seeding_backfill.md`).

**Symptom of a stale database:**

```bash
php artisan tinker --execute="\App\Models\Permission::where('slug','like','result%')->orWhere('slug','like','grade%')->pluck('slug')->toArray();"
# => []   ← stale; must return the 13 slugs below
```

**Phase 3 permissions in this milestone** (registered in the centralized `DatabaseSeeder`):

- results.view, results.view_unpublished
- result_calculation.view, result_calculation.calculate, result_calculation.recalculate
- grade_scales.view, grade_scales.create, grade_scales.update, grade_scales.delete
- result_publishing.view, result_publishing.publish, result_publishing.unpublish

**Safe backfill (DO NOT use migrate:fresh):**

`DatabaseSeeder` is idempotent: `firstOrCreate` for permissions and `sync` for the system roles (`super-admin`, `college-admin`). Re-running it never duplicates rows and never deletes existing records, and it grants the Phase 3 groups to the same two roles that already hold the Phase 1/2 examination permissions.

```bash
# 1. Ensure latest code is pulled (Phase 3 slugs are on main since PR #23)
git pull origin main

# 2. Run migrations (non-destructive)
php artisan migrate --force

# 3. Backfill permissions and roles (safe, idempotent)
php artisan db:seed --force

# 4. Verify
php artisan tinker --execute="\App\Models\Permission::where('slug','like','result%')->orWhere('slug','like','grade%')->pluck('slug')->implode(', ');"
```

**Why not migrate:fresh?**

- Destroys all data (students, admissions, examinations, marks, …).
- Breaks audit history.
- Not safe for staging/production.

**Regression guard:** `tests/Feature/Examinations/ExaminationsModuleSeederTest.php` pins the whole contract — every Phase 3 slug seeded exactly once, Phase 1/2 examination slugs intact, seeding idempotent, both system roles granted under the same convention as the examination permissions, and a seeded college-admin able to open every Examinations screen including the Phase 3 menu items.

**CI / Test:** Tests use `RefreshDatabase` + `seed()` in `TestCase`, so no manual backfill is needed for test runs:

```bash
php artisan test
```
