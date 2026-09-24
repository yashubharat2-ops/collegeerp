# Communication Management — Phase 1 (Dashboard, Notices / Announcements, Circulars, Notifications)

**Scope of this phase:** internal, in-ERP communication only — tenant-scoped
**Notices / Announcements**, formal numbered **Circulars**, internal (in-app)
**Notifications**, and a read-only **Communication Dashboard** over them.

Deliberately **not** implemented (future phases): SMS gateway, e-mail
gateway, WhatsApp, SMS / e-mail templates, delivery logs, external messaging
APIs, push providers and Communication Reports. No tables, permissions,
routes or menu entries exist for them.

Menu: **Communication Management → Communication Dashboard, Notices /
Announcements, Circulars, Notifications** (exactly four entries, each gated on
its own view permission; the group is hidden when the user holds none of the
four view permissions). It sits after Hostel Management, before the closing
Platform / Settings section.

## What was added

| Layer | Artefact |
| --- | --- |
| Migrations (additive only) | `2026_09_27_000001_create_notices_table`, `…000002_create_circulars_table`, `…000003_create_communication_notifications_table` |
| Models | `App\Models\Notice`, `App\Models\Circular`, `App\Models\CommunicationNotification`; marker `App\Models\CommunicationDashboard` (not Eloquent — the dashboard has no table) |
| Domain | `App\Domain\Communication\Services\{NoticeService, CircularService, CommunicationNotificationService, CommunicationDashboardService, CommunicationAttachmentService}`; `App\Domain\Communication\Support\{PublicationWorkflow, CommunicationPriority, CommunicationTypes, CommunicationTargets, NotificationRecipients, CommunicationFilters}`; `App\Domain\Communication\Traits\HasPublicationWorkflow` |
| Policies | `NoticePolicy`, `CircularPolicy`, `CommunicationNotificationPolicy`, `CommunicationDashboardPolicy` (registered in `AuthServiceProvider`) |
| HTTP | `App\Http\Controllers\Communication\{CommunicationDashboardController, NoticeController, CircularController, CommunicationNotificationController}`; Form Requests under `App\Http\Requests\Communication` |
| Routes | `communication` (`communication.dashboard`); resources `notices`, `circulars`, `notifications`; workflow `notices/{notice}/{publish,unpublish,archive}`, `circulars/{circular}/{publish,unpublish,archive}`; downloads `notices/{notice}/attachment`, `circulars/{circular}/attachment`; `notifications/{notification}/{read,unread}`, `notifications/read-all` |
| Views | `resources/views/communication/{dashboard, notices/*, circulars/*, notifications/*, partials/badge}` |
| Config | `config/communication.php` — attachment size / type limits, page size, dashboard panel size, recipient option cap (institution-agnostic) |
| RBAC | 15 slugs in `Database\Seeders\CommunicationPermissionSeeder::PERMISSIONS`, spread into the centralized `DatabaseSeeder` list (idempotent, granted to super-admin and college-admin) |
| Tests | `tests/Feature/Communication/{NoticeTest, CircularTest, NotificationTest, CommunicationDashboardTest, CommunicationNavigationTest, CommunicationSeederTest}` |

No master is duplicated. Notice targets reference the existing
`departments`, `programs` and `sections`; notification recipients reference
the existing `users` (via `user_college` membership), `students` and
`faculties` (the Platform Faculty / Staff master reused by HR).

## Data model

* **notices**: `title`, `slug` (unique per college, archived rows included),
  `notice_type` (extensible normalised label), `content`, `publish_at`,
  `expires_at` (nullable, after `publish_at`), `status`
  (`draft` / `published` / `archived`), `priority`
  (`normal` / `important` / `urgent`), `target_type` (extensible),
  `target_id` (nullable), attachment columns, `created_by` / `updated_by`,
  timestamps, soft deletes.
* **circulars**: `circular_number` (trimmed, upper-cased, unique per college
  including archived rows), `title`, `subject`, `content`, `issue_date`,
  `publish_at` / `expires_at` (nullable), `status`, `target_type`
  (all / students / staff), attachment columns, audit columns, timestamps,
  soft deletes.
* **communication_notifications**: `recipient_type` (`user` / `student` /
  `staff`), `recipient_id`, `title`, `message`, `notification_type`
  (extensible), `priority`, `read_at` (null = unread), `created_by`
  (nullable — system notifications), timestamps. Named so that Laravel's
  framework `notifications` table (database notification channel of
  `User`) stays untouched.
* Every table has `college_id` (restrictOnDelete) and tenant-first composite
  indexes backing the listing filters and dashboard counters.
* `target_id` / `recipient_id` point at different tables depending on the
  type, so they cannot carry a single foreign key; the application validates
  them against the active college (`CommunicationTargets::findEntity`,
  `NotificationRecipients::exists`).

## Rules

* **Tenant isolation**: `BelongsToCollege` + `CollegeScope` on every model;
  records are resolved inside the controllers under the active tenant (a
  foreign id is a 404); policies additionally require the record to belong to
  the active college; services re-assert the tenant before writing.
* **Server-controlled fields**: `college_id`, `slug`, `status`,
  `created_by`, `updated_by`, `read_at` and attachment metadata are stripped
  from requests and stamped server-side.
* **Workflow** (`PublicationWorkflow`): status never comes from a form.
  `publish` (draft | archived → published), `unpublish` (published |
  archived → draft), `archive` (draft | published → archived); invalid
  transitions are rejected; an already-expired record cannot be published;
  archived records are read-only. Publishing a circular without a schedule
  stamps `publish_at`. Visibility (`scheduled` / `live` / `expired`) is
  derived at read time, so no scheduler is needed.
* **Circular numbers** are frozen once the circular leaves draft.
* **Notifications**: the recipient must exist in the active college (active
  member user, non-archived student / staff); it is immutable after sending.
  Read / unread can be toggled by the recipient user (with
  `notifications.view`) or by `notifications.update`; transitions are
  idempotent and audited only when the state changes.
* **Listings**: search, status / priority / type / audience filters, date
  ranges; malformed filters are ignored; deterministic ordering with an `id`
  tie-breaker (`publish_at` / `issue_date` / `created_at` desc, then `id`
  desc).
* **Audit**: every create / update (changed fields only) / delete / publish /
  unpublish / archive / attachment download / read / unread / read-all is
  written through `AuditLogService`.

## Attachment security

* Stored on the private disk under a server-generated key
  `communication/{notices|circulars}/{college_id}/{uuid}.{ext}` — the upload
  name never influences the path.
* Validated by extension/MIME allow-list and size (config), plus a hard
  block-list of executable / scriptable types (php, html, svg, js, exe, …).
* Downloads go through an authorized controller action (view permission),
  re-validate the stored key against the record's own college and area
  (rejecting traversal, absolute paths, stream wrappers, backslashes, nested
  paths), stream with a sanitised filename, and are audited.
* Replaced / removed files are deleted after the transaction commits; soft-
  deleted records keep their file.

## RBAC

| Area | Permissions |
| --- | --- |
| Dashboard | `communication_dashboard.view` |
| Notices | `notices.view`, `notices.create`, `notices.update`, `notices.delete`, `notices.publish` |
| Circulars | `circulars.view`, `circulars.create`, `circulars.update`, `circulars.delete`, `circulars.publish` |
| Notifications | `notifications.view`, `notifications.create`, `notifications.update`, `notifications.delete` |

Existing deployments: `php artisan migrate` then `php artisan db:seed`
(re-syncs the seeded admin roles), or
`php artisan db:seed --class=CommunicationPermissionSeeder` to create the
permissions without touching any role grants.

## Security notes

* All user content is rendered with Blade `{{ }}` escaping; notice / circular
  / notification bodies are plain text with line breaks preserved by CSS
  (no raw HTML rendering). Confirmation dialogs embed titles only through
  `@js()` (JSON-escaped for the HTML attribute context).
* Filters are parsed defensively (`CommunicationFilters`) and never widen the
  tenant scope.
