# Administration / Settings

This module is a web interface over the **existing** authentication, RBAC,
academic, College, institutional settings, Communication and audit models.
There are no new tables or migrations and no external notification provider.

## Navigation and authorization

The Administration / Settings sidebar has exactly eight policy-filtered entries,
in this order: Users, Roles, Permissions, Academic Configuration, Institution
Settings, Notification Settings, Audit Logs, System Settings. Create/edit/status
operations stay inside their screens, not in the sidebar. There is no blanket
administration gate that accidentally blocks users with a specific capability.

Routes are defined in `routes/administration.php`. All use `auth` and the existing
`tenant` middleware. College operations also require `tenant.access`. Shared
User and Role IDs are explicitly looked up **after** tenant resolution, never
implicitly route-bound before it. CollegeScope and TenantContext are unchanged.

## Users

- Membership uses `user_college`; permissions use `role_user` and
  `permission_role`. User remains the shared login identity (no global scope or
  duplicate user table is added).
- `users.view`, `users.create`, `users.update`, and `users.assign_roles` are
  separate capabilities. Status changes require update authority.
- New accounts get a cryptographically random, hashed, undisclosed password.
  Users set their own password through the **existing Forgot Password / password
  broker flow**, using whatever mail delivery the installation already has.
  Creating a user sends no message and adds no mail/SMS provider. There is no
  administrative password/reset-token field.
- College administrators can edit global profile/status only for identities
  belonging exclusively to their college and having no platform role grants.
  Shared/platform identities need a Super Admin for these global changes.
- Role replacement touches only the active college's pivots. Other colleges and
  null-college platform grants survive. Own role assignments and self-deactivation
  are blocked. Administrators cannot grant or take over roles more privileged
  than their own active-college authority.
- Deactivation uses the existing `is_active` flag, revokes remembered login and
  deletes database-backed sessions when that session driver is configured. Other
  drivers retain the application's existing permission-denial behaviour; no
  second session/authentication architecture is added.
- Super Admins can explicitly associate an existing identity with the active
  college. Existing passwords, memberships, default selection and role grants
  are preserved. Creating an account with an already-used email does not link or
  disclose an account from another college.
- No destructive user deletion endpoint is exposed. Retained college role pivots
  preserve their original assignment timestamps.

## Roles and permissions

Custom roles are college-scoped. Identifiers and ownership are immutable after
creation; reserved platform/system identifiers cannot be supplied. System roles
are protected from college administrators and the actor's own role is protected.
Super Admins can maintain college system roles. Global/platform definitions are
read-only here; the existing global Super Admin grant is preserved.

Permissions displays the existing centralized `permissions` registry, grouped
by its stored module, including inactive definitions. It links administrators to
the same role permission editor used by Roles: it neither creates nor edits
permission definitions. The global registry also remains readable to Super Admins
without a selected tenant, but college role assignment requires an active tenant.
Only active registry IDs may be assigned, and every
grant is checked against the actor's existing tenant-aware permission checks.
An explicit empty selection clears assignments; omitted permissions in a
metadata-only update do not silently revoke them. Saving the full permission
selection removes inactive assignments, as stated in the editor.

## Academic Configuration

This is a policy-filtered hub over Academic Year, Academic Term, Department,
Program, Section, Subject, Campus and GradeScale. Counts and summaries use the
existing CollegeScope. Management links lead to the existing controllers,
validators and policies; the hub does not grant write authority or duplicate
academic masters. The active session comes from the existing active Academic Year.

## Institution Settings

Institution name, email, phone and address are updated on **College**. Basic
branding uses the existing `institutional_settings` rows:

- `branding.short_name` (navigation display)
- `branding.logo_path` (private, server-generated image reference)

Only this allowlist is read into application chrome. Arbitrary historical
settings are not listed or editable. `/settings` and its legacy POST endpoint
remain available, but now use the same structured, validated profile contract;
the old arbitrary key/value/type writer is intentionally removed.

Logos are PNG/JPEG/WebP, at most 2 MB and 2,000 × 2,000 pixels. SVG is excluded.
Storage reuses SecureFileService and the private disk, with tenant-specific
paths, rollback cleanup and post-commit replacement cleanup. The authorized
logo stream validates the stored tenant path and detected image MIME type,
returns private/no-store cache headers, and cannot read arbitrary disk paths.
No deployment credentials, college ownership/status or stable identifiers can
be edited through the institution form. Existing setting creators are preserved.

## Notification Settings

The screen shows the existing in-app capabilities and deployment-managed
Communication limits. Template visibility/activation uses the existing
CommunicationTemplatePolicy and CommunicationTemplateService, with their normal
tenant locks and audit events. SMS/email templates are definitions **only**;
activation does not send anything. Definition editing and creation reuse the
existing Communication screens. No unsupported delivery switches, inert tenant
provider settings, credentials or gateways are added.

## Audit Logs and System Settings

Audit Logs queries the existing immutable log. The default query is always the
active college, independently of submitted filters. Actor/action/module and UTC
inclusive date filters are available. A Super Admin may deliberately select
`scope=platform` for a cross-college/platform read, with an optional college
filter. Raw old/new payloads, headers, user agents and IPs are excluded at the
SELECT boundary, so legacy unredacted secrets cannot reach the view. There are
no audit write/delete/export endpoints.

System Settings separates college-owned settings from a Super-Admin-only,
read-only allowlist of safe deployment configuration (timezone, locale, session
lifetime and Communication limits). It never dumps environment/configuration or
provider hosts/keys. The existing system has **no platform settings persistence
store**, so no misleading platform write form or new settings table is invented.
The college switch uses the existing authorized switch endpoint and grant rules.

## Deployment and verification

No migrations are needed. On an existing installation, register the module's
centralized entries without overwriting users, roles, activation or grants:

```sh
php artisan db:seed --class=AdministrationPermissionSeeder --force
```

Existing role grants are deliberately unchanged by that standalone seeder.
Use a trusted Super Admin to grant the new user-management, role-creation and
audit-view capabilities to the intended college roles. Do **not** run the demo
DatabaseSeeder on production to provision permissions; fresh/dev installations
use that seeder's same centralized list.

Focused feature tests are in `tests/Feature/Administration`, covering navigation,
RBAC, tenant isolation (including Super Admin boundaries), CRUD/activation,
privilege escalation, shared/global pivots, existing identity linkage, private
logos, safe settings, template availability, read-only logs and platform scope.

Normal development checks:

```sh
composer validate --strict
composer test
php artisan test --filter=Administration
vendor/bin/pint --test --dirty
php artisan route:list --path=admin
php artisan view:cache
npm ci
npm run build
git diff --check
```

### Arena implementation verification

Completed against the locked dependencies with PHP 8.5.10 WebAssembly and
PHPUnit 11.5.56 (native PHP/Composer binaries were unavailable and binary
installation/download attempts were blocked). The locked dependency source
archives were recovered without changing either dependency manifest or lockfile.

- Administration suite: **47 tests, 803 assertions, all passed**.
- Complete regression set, run in memory-bounded batches: **1,618 tests accounted
  for; 1,617 passed, 1 existing skip, 16,279 assertions, zero failures/errors**.
  The existing skipped test is
  `AcademicYearConstraintTest::test_academic_year_database_check_constraint_exists_for_supported_drivers`:
  that test deliberately skips its raw database CHECK verification under SQLite.
- Related navigation regression selection: **71 tests, 1,984 assertions, passed**.
- All Unit tests: **6 tests, 37 assertions, passed**.
- Pint on all changed PHP files: **55 files, passed**.
- PHP syntax checks, production frontend build, JavaScript syntax checks, JSON
  manifest parsing and `git diff --check`: passed.
- Laravel route cache/list (23 Administration routes retaining auth and tenant
  middleware), Blade view compilation, SQLite fresh migrations and seeding:
  passed. No module migrations were added.

The WebAssembly compatibility configuration was restricted to temporary,
ignored dependency files: console-output partial mocking was bypassed (with
real console exit codes still asserted), and failed-login wall-clock delay was
neutralized without changing credential validation. Those temporary runtime
changes are **not** part of the implementation and were restored after testing.
An initial unbounded regression attempt exceeded the runtime's 128 MB memory
limit; the complete test set was subsequently verified in bounded batches.

Native `composer validate --strict`, the native Composer/Artisan test wrappers,
and MySQL/PostgreSQL integration checks were not executable in this environment.
The native Composer/PHP binaries and database services were not available;
framework/tests were executed directly through the WebAssembly runner instead.
