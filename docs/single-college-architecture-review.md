# Single-College Architecture Review

Date: 2026-10-23

Scope: navigation restructuring only; no schema, model, route, middleware, or workflow changes are proposed by this review.

## Decision for this implementation

The main sidebar no longer offers **Campuses**. The six retained academic destinations from the former Platform group remain under **Academics**, and **Human Resource (HR)** remains between **Inventory** and **Reports**.

No database change is required for this navigation change. In particular, this implementation does **not** remove the `Campus` model, `campuses` table, campus routes, permissions, relationships, or administration pages. The existing `campuses.index` and `campuses.store` routes remain protected by the existing authentication, tenant, and policy checks. The Campus entry in Administration → Academic Configuration is an existing destination outside the main sidebar and was not changed.

Removing campus persistence or campus fields from operational forms is a separate data-model and UX project, not a prerequisite for hiding the main-sidebar link.

## Campus dependency inventory

A codebase-wide inspection covered `app/`, `database/`, `routes/`, `resources/`, and `tests/` for the Campus model, campus relationships, `campus_id`, `campuses` table references, and campus routes.

### Direct schema dependencies

| Table | Relationship | Creation impact | Removal impact if Campus were retired |
| --- | --- | --- | --- |
| `campuses` | Required `college_id`; soft deletes; code unique per college | Campus records are college-owned | This is the owned master table, not an isolated table with no consumers |
| `departments` | Nullable `campus_id` FK with `nullOnDelete` | A campus is optional | Department forms, exports, model relations, and campus labels would need review |
| `sections` | Nullable `campus_id` FK with `nullOnDelete` | A campus is optional; academic year and program are required | Section forms/filters, audit fields, operational views, reports, and exports would need review |
| `academic_timetables` | Nullable `campus_id` FK with `nullOnDelete` | A campus is optional | Timetable validation, forms, model relations, reports, and exports would need review |
| `exam_schedules` | Nullable `campus_id` FK with `nullOnDelete` | A campus is optional | Exam scheduling validation/conflict logic, forms, model relations, and exports would need review |

Relevant migrations include:

- `2026_09_05_000002_create_campuses_table.php`
- `2026_09_05_000009_create_departments_table.php`
- `2026_09_19_000002_create_sections_table.php`
- `2026_09_20_000001_create_academics_operational_tables.php`
- `2026_09_20_000003_create_exam_schedules_table.php`

The nullable FKs use `nullOnDelete`; physically deleting campus records can therefore discard the stored location association on dependent rows. Soft-deleting the Campus model does not make the surrounding UI, validation, or historical-label requirements disappear.

### Application dependencies

The following code still consumes campus data:

- `CampusController`, `StoreCampusRequest`, `CampusPolicy`, and `resources/views/campuses/index.blade.php` provide the existing campus management endpoint.
- `College::campuses()` and `Campus`/`BelongsToCollege` provide college ownership and tenant scoping.
- `DepartmentController`, department requests/views/exports, and `Department::campus()` support optional campus assignment.
- `SectionController`, section requests/views, and `Section::campus()` support optional campus assignment and filtering.
- `AcademicsController`, `AcademicTimetable::campus()`, timetable views, academic reports, and `AcademicsExportController` consume optional timetable/section campus labels.
- `ExamScheduleController`, exam-schedule requests/views, `ExamSchedule::campus()`, and exports consume optional campus data; exam conflict checks also consider campus context.
- `StudentIdCardService` reads the current enrollment's section campus for ID-card payload/display data.
- `Admin\AcademicConfigurationController` lists the existing Campus management destination.
- Campus permissions remain in RBAC seeding, policies, and administration permission tests.

These dependencies are reasons not to delete the model, table, routes, or foreign keys as part of a sidebar-only task.

## Does creating a record require a Campus?

| Workflow | Direct Campus dependency | Current requirement |
| --- | --- | --- |
| Programs | None on `programs`; optional `department_id` can indirectly reference a department whose own campus is nullable | **No campus required.** Program validation requires college-scoped fields and permits no department |
| Academic Years / Terms | None | **No campus required** |
| Sections / Batches | Nullable `sections.campus_id` | **No campus required.** Academic year and program are required and tenant-validated |
| Subjects | None on `subjects`; optional department may have a nullable campus | **No campus required** |
| Admissions / Admission Applications | No `campus_id`; academic year and program references are nullable | **No campus required** |
| Students | No `campus_id` on `students`; enrollment section is optional | **No campus required.** Student ID cards may display a campus indirectly through enrollment → section |
| Faculty / Staff | No direct campus FK; optional department may have a nullable campus | **No campus required** |
| Faculty–Subject Assignments | No `campus_id`; program and section references are nullable | **No campus required.** Any selected section is tenant- and academic-context-validated |

Consequently, removing the Campus sidebar link does not prevent these create workflows from functioning. Existing records that already store campus associations continue to render and export those associations unchanged.

## Multi-college and tenant architecture

The application is still architected as a tenant-aware modular monolith even when a deployment serves only one college:

- `colleges`, `user_college`, and role/permission pivots represent college membership and college-scoped authorization.
- `ResolveTenant` resolves the active college from authorized membership/default selection or an explicitly authorized Super Admin selection. `EnsureTenantAccess` requires a college context for tenant-owned routes.
- The academic and campus destinations remain inside the existing `auth` + `tenant` + `tenant.access` route group in `routes/web.php`.
- `BelongsToCollege` attaches server-side college ownership on creation, and `CollegeScope` restricts tenant-owned queries. With no tenant context, the scope is deny-by-default (`1 = 0`).
- Form Requests validate related IDs against the active `college_id`; several also remove browser-supplied ownership/actor fields. Controllers explicitly resolve records through tenant-scoped queries where implicit binding would occur too early.
- `CollegeSwitchController` remains the authorized switch boundary. The existing layout only shows its selector when more than one accessible college is available, so a one-college deployment does not require removing this control or its middleware.
- Page-level policies still enforce `viewAny`/action permissions independently of sidebar visibility. Hiding or moving a link is not an authorization mechanism and does not grant access.

**No single-college navigation requirement justifies removing `college_id`, `CollegeScope`, tenant middleware, college-scoped validation, or RBAC.** Those controls remain necessary for data ownership, safe future deployments, and defense in depth.

## Future data-model options and impact

### Option A — retain dormant campus compatibility (no migration)

Keep the current nullable campus fields and relationships while omitting Campus from the main sidebar. This is the smallest safe option and the one used for this task. Existing records, operational forms, reports, exports, and ID cards retain their current behavior.

### Option B — fully retire the Campus concept (separate approved project)

This is **not required** for the navigation objective and is **not implemented**. If later approved, it needs an explicit design covering all of the following:

1. Decide how historical campus/location labels on departments, sections, timetables, exam schedules, reports, exports, and student ID cards must be preserved or replaced.
2. Inventory production data, including soft-deleted records, and take a restorable backup. Identify rows whose campus association carries business meaning before any FK is changed.
3. Deploy backward-compatible application changes first: remove writes and UI dependencies while keeping reads/migrations compatible during rollout. Update validation, conflict logic, audit field lists, exports, reports, and tests without weakening tenant checks.
4. Use a reviewed, production-engine-specific migration strategy. Dependent FKs/indexes/columns must be handled before the campus table; destructive steps need an explicit maintenance/rollback plan and representative-data testing. A model-only deletion is not a safe substitute.
5. Keep College tenant boundaries and authorization intact. Retiring Campus must not become a multi-tenant security removal project.
6. Run admission conversion, student enrollment/ID-card, academic/timetable/exam, HR/department, reporting/export, RBAC, and tenancy regression suites before and after the migration.

Until that separate review is approved, no destructive campus migration is justified.
