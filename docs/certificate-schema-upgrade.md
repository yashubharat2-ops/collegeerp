# Certificate schema reconciliation

## Why “Pending” can coexist with certificate tables

Laravel checks the migration **filename** against its migration repository; it does not infer that a migration ran because its tables exist.

Repository inspection found the earlier Group 1 implementation on `arena/01a0e229-collegeerp` in `2026_09_27_000004_create_certificate_management_tables.php`. That migration creates the legacy template schema (`type`, `is_active`, `created_by`) as well as legacy requests/issuances. The next legacy migration adds a template reference to StudentTransfer. Neither migration is the later `2026_10_01_000001_create_certificate_management_tables` migration, so the latter can legitimately remain pending against the same database after a branch/schema change.

A second possible contributor is a partially failed attempt at the newer migration: it creates `certificate_types` first, then fails because `certificate_templates` already exists. On MySQL, the first DDL statement can remain committed even though Laravel never logs the migration as successfully applied. That would explain a new-looking types table alongside legacy templates and a pending migration. This cannot be confirmed without the local schema/history/error logs.

That inspected older migration does **not** create `certificate_types`. Its exact local origin cannot be determined from the repository or without inspecting the user's database history. Do not assume those rows are disposable or insert a fabricated migration-history entry. The upgrade handles the tenant-owned catalog by inspecting its actual columns and validating the rows.

## Migration paths

- **Fresh:** the updated `2026_10_01_000001` creates the shared tables.
- **Pending migration with legacy tables:** the same migration upgrades the tables in place instead of calling `CREATE TABLE` unconditionally.
- **Original migration already applied:** `2026_10_01_000002` runs the same frozen reconciliation logic. Re-running reconciliation on an already-upgraded schema does not recreate templates/types or rewrite certificate records.

The implementation lives under `database/migrations/support` and uses only the schema/query builders, not evolving application models or seeders. It provisions the seven built-in rows for every existing college without a seeder. Future colleges retain the existing application's catalog provisioning behavior.

## Mapping and preservation

- Existing type and template primary keys remain intact. Template bodies, names, active flags, authors, timestamps, and type descriptions remain intact.
- Legacy `code`, `short_code`, `slug`, `type`, and name aliases identify built-in types. For example, `tc`, `transfer`, and `Transfer Certificate (TC)` resolve to that college's `TC` row; `bonafide` resolves to `BON`.
- A normalized old code is retained in `legacy_code`. Legacy catalog `slug`, `short_code`, and `type` fields are retained but made nullable. The template `type` column is renamed to nullable `legacy_type`: retaining it as `type` would shadow the new Eloquent `CertificateTemplate::type()` relationship. Original text remains available, while new application writes need only `certificate_type_id`.
- Unknown nonempty template tokens become college-local custom types, rather than being incorrectly assigned to a built-in. A normalized, length-limited code is generated. Collisions/ambiguous mappings stop the migration rather than merge or delete catalog rows.
- Templates receive `certificate_type_id` by looking up aliases **within the same college**. An existing valid same-college ID is authoritative; stale legacy text is preserved as historical metadata. Missing/foreign-college IDs are rejected.
- College-local code/built-in uniqueness replaces obsolete global code uniqueness. Composite `(certificate_type_id, college_id)` foreign keys prevent templates referencing another college's catalog. A code-based foreign key from another legacy table requires an explicit reviewed conversion first; it is not silently broken.
- Existing `certificates` records are untouched. If that table is absent it is created with the shared workflow schema. Number counters are retained/advanced beyond existing numbered records, never reset.
- Other legacy tables such as `certificate_requests` and `certificate_issuances` are **not deleted or reinterpreted as new workflow records**. Issuance documents and StudentTransfer references to template IDs remain intact. Importing old requests/issuances into the new workflow would require separate status/enrollment reconciliation and is outside this types/templates schema repair.

Tenant-owned legacy catalogs must have `id`, `college_id`, and `name`. Legacy templates must have `id`, `college_id`, `name`, and `body`. A global catalog without ownership, invalid college IDs, unknown required fields without defaults, conflicting type identifiers, or an incompatible pre-existing `certificates` table produces an actionable error before schema mutation. The migration does not guess a college, fabricate an author, silently truncate long identifiers, or discard unrecognized records.

## Operational safety

Back up the database and rehearse on a copy first. Stop application writes during the upgrade, especially because MySQL DDL commits implicitly:

```sh
php artisan down
# Take and verify a database-native backup.
php artisan migrate --force
php artisan migrate:status
php artisan up
```

No `migrate:fresh`, table drops, seeder invocation, migration-history edits, route changes, or backend redesign are needed. If validation fails, keep the application in maintenance mode, review the indicated source rows/constraints, and retry after an explicit data correction. Do not mark the pending migration as run.

On SQLite the migrations run outside a surrounding migration transaction. Foreign-key actions are temporarily disabled during schema-builder table rebuilds, restored in `finally`, and the reconciled tables are foreign-key checked afterward. This prevents a parent table rebuild from cascading deletes/null assignments into historical issuance or StudentTransfer rows. Template/type backfill itself is transactional. Existing foreign-key settings are restored even on an error.

Both migrations are intentionally **forward-only**: `down()` raises an explanatory error rather than dropping adopted certificate data. To undo the upgrade, restore a verified backup; do not attempt a destructive rollback.

## Verification

```sh
php artisan test --filter=CertificateSchemaUpgradeTest
php artisan test --filter=Certificate
```

The focused tests use their own in-memory SQLite database without seeders or `migrate:fresh`. They cover fresh installation, legacy catalogs and templates, template-only legacy databases, all built-in aliases and custom tokens, exact data preservation, legacy foreign references, new application writes, tenant isolation, repeatability/current certificate preservation, counters, validation failures, normal pending-migration execution, and rollback protection.

PHP is unavailable in the implementation sandbox, so these tests must be run in the application's PHP environment before deployment. PHP 8.2 static parsing and `git diff --check` were run separately; they are not substitutes for database integration tests. Production MySQL/PostgreSQL upgrades should also be rehearsed on the production database engine.
