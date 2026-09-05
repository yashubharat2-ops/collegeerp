# College ERP Platform Foundation

## Architecture
The application is a Laravel modular monolith. Platform capabilities live in `app/Support`, `app/Services`, `app/Actions`, and shared models. Future business capabilities must be introduced as bounded domains under `app/Domain` without coupling controllers directly to persistence.

## Tenancy
A user may access zero, one, or many colleges through `user_college`. `TenantContext` is request-scoped and must be explicitly resolved for HTTP requests and jobs. Tenant-owned models use `BelongsToCollege`, which applies a global college scope. Super Admins may be assigned system roles and explicitly select an accessible college; they do not bypass tenant scope implicitly.

## RBAC
Roles and permissions are database records. System roles may be global; custom roles are college-scoped. Assignments include the college context. Authorization belongs in policies and permission checks, never in hard-coded controller branches.

## Database conventions
Use foreign keys, explicit indexes, unique constraints, UTC timestamps, status fields, and decimal types for monetary values. Do not add future business tables until their module is approved. Audit logs are append-only application records. Academic-year date validity is enforced by a database check constraint; overlap and one-active-year rules are validated inside a college-row transaction for concurrency safety. A production-specific partial/generated unique index should be added once the production database engine is fixed.

## Security rules
Use Form Requests, native hashed passwords, session regeneration, login throttling, CSRF protection, private file storage, authorization before resource access, and generic production error responses. Never log passwords, tokens, secrets, or raw credentials.

## Module boundaries and workflow
Controllers remain thin. Use Actions for transactional workflows, Services for reusable coordination, Policies for authorization, Events/Listeners for cross-cutting reactions, Jobs for asynchronous work, and API Resources for API contracts. Run migrations and tests in CI; never modify production data manually.
