# Transport Management — Phase 1

## Scope

Only Transport Dashboard, Vehicles, Drivers, and Routes / Stops. No assignments,
fees, passes, GPS, attendance, reporting, or maintenance workflows are included.
`maintenance` is only a Vehicle status.

## Deployment

Apply the four additive `2026_09_24_*` migrations with `php artisan migrate`.
For an existing deployment, run:

```sh
php artisan db:seed --class=TransportPermissionSeeder
```

This idempotent standalone seeder creates the 13 permissions without changing
existing permission states, role grants, users, or demo data. Grant the needed
permissions to existing college roles using the ERP's RBAC administration.
The existing DatabaseSeeder also includes them in its standard demo admin grants.
Do not run destructive migration commands on an existing ERP database.

## Data and history decisions

- Tenant context is required for all screens, including Super Admin. Existing
  authorized college-switch rules apply; there is no cross-college overview.
- Drivers must reference an existing, non-deleted `Faculty` record in the active
  college. No staff identity, contact or address data is duplicated.
- Registration numbers, license numbers and route/stop codes are trimmed and
  uppercased consistently in validation and model setters. Internal punctuation
  and whitespace are retained (no jurisdiction-specific registration format).
- Those identifiers remain reserved after soft deletion. This is intentionally
  stricter than some older masters and preserves unambiguous historical identity.
- License expiry is required and must be an actual ISO date. Past expiry dates are
  allowed so existing/expired licenses can be accurately recorded; no renewal or
  automatic deactivation workflow is implemented in Phase 1.
- Stops use positive, unique live sequence numbers per route, sorted by sequence
  then ID. Gaps are allowed. Reordering uses edits; use a spare sequence number
  when exchanging two positions. Pickup/drop times are optional 24-hour `HH:MM`.
- Stops use route create/update/delete permissions for the corresponding action.
  Route deletion is refused until its live stops are deleted; marking it inactive
  preserves the route and stops unchanged. All four masters soft-delete.
- Tenant/actor fields and stop ownership are server-controlled and not fillable.
  Stop ownership comes only from the scoped nested URL; reparenting is not exposed.
- Transactions cover mutations and audits. A college-row lock serializes checks
  and writes; unique indexes also guard identifiers, live stop sequence and active
  driver/staff uniqueness. A composite foreign key guards stop/route college
  consistency. Driver/staff tenancy is rechecked in the service.
- Partial unique indexes are used on SQLite/PostgreSQL; MySQL/MariaDB use nullable
  generated keys for active driver/staff and live stop sequence uniqueness.
- Shared Transport-only CRUD views/controllers/validation use explicit model field
  allowlists. Existing modules are not changed beyond shared route, navigation,
  policy-registration and seeding integration.

## Validation commands

```sh
php artisan test --compact tests/Feature/Transport
php artisan test --compact
npm run build
git diff --check
```

Tests cover tenant scope, all resource RBAC boundaries, navigation, authorized
Super Admin switching, CRUD and audit snapshots, normalized uniqueness, dates,
existing staff validation, active driver duplicates, stop sequence order, parent
ownership, database constraints, and idempotent permission seeding.

### Sandbox verification

The frontend build passes. PHP test execution is blocked in this sandbox: PHP,
Composer and `vendor/` are absent, and provisioning attempts to Debian package
hosts and GitHub release-asset hosts failed at the network/TLS layer. Neither the
focused suite nor the full suite has been verified here. Run both before release;
verify migrations on the deployment database engine as well. No application
database migrations or destructive database operations were executed here.
