# Hostel Management — Phase 1 (Dashboard, Hostels, Buildings / Blocks, Rooms, Beds)

**Scope of this phase:** the hostel *infrastructure masters* only —
`hostels`, `hostel_buildings`, `hostel_rooms`, `hostel_beds` — plus a
read-only **Hostel Dashboard** over them. Hostel Allocation, Hostel Fees,
Hostel Attendance, Visitors and Hostel Reports are deliberately **not**
implemented; their tables are not created here, no student is allocated to a
bed, and no menu entries exist for them yet.

Menu: **Hostel Management → Hostel Dashboard, Hostels, Buildings / Blocks,
Rooms, Beds** (exactly five entries, each individually permission-gated).

The core hierarchy is:

```
College → Hostel → Building / Block → Room → Bed
```

These are four separate masters — they are never combined. Student,
StudentEnrollment, Academic Year, Department, Program and Campus are not
touched; they already exist elsewhere in the ERP. A future Phase 2 will
connect existing `StudentEnrollment` records to Beds through Hostel
Allocation.

## What was added

| Layer | Artefact |
| --- | --- |
| Migrations | `2026_09_26_000001_create_hostels_table`, `…000002_create_hostel_buildings_table`, `…000003_create_hostel_rooms_table`, `…000004_create_hostel_beds_table` |
| Models | `App\Models\Hostel`, `App\Models\HostelBuilding`, `App\Models\HostelRoom`, `App\Models\HostelBed`; marker `App\Models\HostelDashboard` (not Eloquent — the dashboard has no table) |
| Domain | `App\Domain\Hostel\Services\{HostelService, HostelBuildingService, HostelRoomService, HostelBedService, HostelDashboardService}`, `App\Domain\Hostel\Support\HostelFormOptions` |
| Policies | `HostelPolicy`, `HostelBuildingPolicy`, `HostelRoomPolicy`, `HostelBedPolicy`, `HostelDashboardPolicy` (registered in `AuthServiceProvider`) |
| HTTP | `App\Http\Controllers\Hostel\{HostelDashboardController, HostelController, HostelBuildingController, HostelRoomController, HostelBedController}`; Form Requests under `App\Http\Requests\Hostel`; routes `hostels/dashboard` (`hostels.dashboard`), `hostels`, `hostel-buildings`, `hostel-rooms`, `hostel-beds` (resources, no `show`) |
| Views | `resources/views/{hostel_dashboard,hostels,hostel_buildings,hostel_rooms,hostel_beds}/…` |
| Navigation | new **Hostel Management** sidebar section with exactly five permission-gated entries (placed between Transport Management and Library Management) |
| RBAC | `hostel_dashboard.view`; `hostels.*`, `hostel_buildings.*`, `hostel_rooms.*`, `hostel_beds.*` (`view/create/update/delete`) — 17 slugs, centralized in `DatabaseSeeder` (via `HostelPermissionSeeder::PERMISSIONS`), idempotent, granted to super-admin and college-admin |
| Tests | `tests/Feature/Hostel/{HostelsTest, HostelBuildingsTest, HostelRoomsTest, HostelBedsTest, HostelDashboardTest, HostelNavigationTest, HostelModuleSeederTest}` |

No Platform master is duplicated: every row references the existing
`colleges.id`, and `created_by` / `updated_by` reference `users.id`.

## Data model

* **Hostel**: `name`, `code`, `hostel_type`, `gender`, `address`,
  `description`, `status`.
* **HostelBuilding**: `hostel_id`, `name`, `code`, `floors` (optional),
  `description`, `status`.
* **HostelRoom**: `hostel_id` (denormalized, server-derived), `building_id`,
  `room_number`, `floor` (optional), `room_type` (optional free text),
  `capacity`, `description`, `status`.
* **HostelBed**: `hostel_id`, `building_id` (both denormalized,
  server-derived), `room_id`, `bed_number`, `status`
  (`available` / `occupied` / `inactive`), `description`.
* Every table: `college_id` (restrictOnDelete), `status`
  (`active` / `inactive`), `created_by` / `updated_by`, timestamps, soft
  deletes, covering indexes on `(college_id, …)`, and a **composite foreign
  key** `(parent_id, college_id) → parent (id, college_id)` so same-tenant
  ownership is guaranteed at the database level (the same approach as
  `transport_stops`).

## Future architecture

* **Bed status is Phase 1 operational data only.** From Phase 2, Hostel
  Allocation owns occupancy: it connects existing `StudentEnrollment` records
  to beds and becomes the source of truth for which bed is occupied. Phase 1
  deliberately has no student allocation of its own (and no fake placeholder
  tables).
* Identifiers (`hostels.code`, `hostel_buildings.code`,
  `hostel_rooms.room_number`, `hostel_beds.bed_number`) stay **reserved on
  archived records** so allocation/history references can never become
  ambiguous.
* Denormalized `hostel_id` on rooms and `hostel_id`/`building_id` on beds let
  Phase 2 and Phase 3 filter by any level of the hierarchy without joins; the
  services always derive them from the owning parent.

## Data rules

* Codes are stored upper-cased and trimmed; room/bed numbers are trimmed.
* `hostel_type` (`boys`, `girls`, `mixed`) and `gender` (`male`, `female`,
  `any`) are validated against the model constants
  (`Hostel::TYPES` / `Hostel::GENDERS`) — a single editable place, so the
  module is not hard-coded against future expansion.
* Names are unique per college (per hostel for buildings) among **active**
  records; archiving frees a name, archiving never frees a code.
* A room's `floor` must fit the building's declared `floors` (when set); a
  building's `floors` and a room's `capacity` are positive integers.
* A room's `capacity` is its bed ceiling: beds cannot be added beyond it, and
  capacity cannot be lowered below the room's current live bed count.
* An occupied bed cannot be deleted (mark it available or inactive first);
  from Phase 2, allocation history will hard-block deletion entirely.

## Tenancy

* All four models use `BelongsToCollege`, so every query runs under
  `CollegeScope` (which resolves to `1 = 0` without a tenant) and `college_id`
  is stamped from `TenantContext`. The Form Requests strip `college_id`,
  `created_by`, `updated_by` (and the server-derived `hostel_id` /
  `building_id` on children) from the payload; the services stamp them from
  the tenant context, the authenticated user and the owning parent.
* Controllers resolve models with `Model::query()->findOrFail($id)` under the
  active tenant — never implicit route model binding — so a foreign-tenant id
  is a plain 404 for `edit` / `update` / `destroy`.
* Foreign keys are validated **contextually**: a building's hostel, a room's
  building and a bed's room must be live (not soft-deleted) rows of the active
  college (`Rule::exists(...)->where('college_id', …)->whereNull('deleted_at')`),
  and the services re-check the same rule before every write, so a forged
  cross-tenant id is rejected even if the Form Request were bypassed.
* A child's parent is **immutable** after creation (never reparented): the
  update Form Requests strip the parent id and the services refuse a forged
  one.
* The option lists (`HostelFormOptions`) are read through the scoped models,
  so a form can only ever offer the active college's masters.
* Existing Super Admin active-college / tenant-switch rules are unchanged —
  a super admin works inside an active college like everywhere else.

## Deployment

Apply the four additive `2026_09_26_*` migrations with `php artisan migrate`.
For an existing deployment, run:

```sh
php artisan db:seed --class=HostelPermissionSeeder
```

This idempotent standalone seeder creates the 17 permissions without changing
existing permission states, role grants, users, or demo data. Grant the needed
permissions to existing college roles using the ERP's RBAC administration.
The existing DatabaseSeeder also includes them in its standard demo admin
grants. Do not run destructive migration commands on an existing ERP database.

## Validation commands

```sh
php artisan test --compact tests/Feature/Hostel
php artisan test --compact
```
