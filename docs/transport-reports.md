# Transport Reports (read-only)

`/transport-reports` is the seventh entry in the REPORTS sidebar section, after
Library Reports (the section stays in its approved position, immediately after
Inventory / Asset Management). The single `transport_reports.view` permission
grants all six views. It does **not** grant access to the operational Transport
pages (dashboard, vehicles, vehicle documents, drivers, routes, stops, student
transport assignments, transport fees) and those pages' permissions do not
grant the reports. Only one GET route exists; no report tables, snapshots,
exports or cached aggregates are created. Transport Reports lives in the
REPORTS section (with a 🚐 transport icon), not in the Transport
Management group, which keeps its eight operational entries.

| View | Source / interpretation |
| --- | --- |
| Vehicle Report | `vehicles` rows of the active college with the stored fleet details: registration number, vehicle type, make / model, seating capacity, purchase date, insurance / fitness / permit expiry and fleet status. Filters: vehicle, vehicle status, vehicle type. |
| Driver Report | `transport_drivers` rows with the existing staff record they reference (driver name, employee code, e-mail / phone), license number / type / expiry, joining date and status. Filters: driver, driver status, license-expiry window. The existing schema keeps drivers, vehicles and routes as independent masters (there is no driver → vehicle assignment), so the Assigned vehicle column shows `—` — the report never invents a pairing. |
| Route / Stop Report | `transport_routes` rows with their existing `transport_stops` in the stored sequence (sequence, code, pickup / drop time, landmark, status) and live student assignment counts per route and stop. The route → stop relationship is the one Transport Management maintains; nothing is duplicated. Filters: route, stop, route status. |
| Student Transport Assignment Report | `student_transport_assignments` rows reusing the Student + Enrollment + Transport Assignment relationships: student (name / number), enrollment number, program / class and section, academic year, route, stop, period (start / end date) and assignment status. Assignments reference a route and stop only (no vehicle link exists), so the Vehicle column shows `—`. Filters: academic year, student, program (class), section, route, stop, assignment status, start-date window. |
| Transport Fee Report | `student_transport_fee_assignments` rows with the assigned / collected / due figures derived by the existing `TransportFeeService` ledger (the shared `FeeLedger` arithmetic over the existing `fee_payments` / `fee_refunds` Finance rows). The report never recalculates or stores an amount. Filters: academic year, student, route, stop, fee status, effective-from window. |
| Transport Summary | Live totals for the whole college: vehicles (total / active / inactive / maintenance / retired), drivers (total / active / inactive), routes and stops (total / active / inactive), student transport assignments (total / active / completed / cancelled) and transport fees (assignments by status plus assigned / collected / due and assignments carrying a balance). No filters: the summary always describes the active college. |

Filters (only those relevant to the selected view are shown and applied; any
other query parameter is ignored, and a status value a view cannot use is
rejected only where that view actually uses the filter).

Implementation notes:

* `App\Domain\Transport\Services\TransportReportService` builds every root
  query and aggregate from models carrying `CollegeScope` (and `SoftDeletes`),
  so rows and counts never cross colleges and soft-deleted records are
  excluded — exactly as the operational screens show them. Completed /
  cancelled assignments and fee assignments are history and stay visible. An
  id from another college in a filter simply matches nothing.
* No Transport or Finance rule is re-implemented: statuses, route → stop
  sequencing and fee amounts are the ones the operational services stored
  (`TransportMasterService`, `StudentTransportAssignmentService`,
  `TransportFeeService`). Assigned / collected / due come from the same
  `TransportFeeService::ledgerFor()` derivation the Transport Fees screen uses,
  itself the shared `FeeLedger` formula over the existing Finance rows.
* Lists paginate 20 per page with a unique-ID tiebreak and keep the query
  string; ordering is deterministic (registration number, license number, route
  name / stop sequence, dates, id). Counts and sums are SQL aggregates and
  every relation a row renders is eager loaded — the Route / Stop Report
  pre-aggregates the student counts of a whole page in one grouped query — so
  the query count does not grow with row counts (a test asserts this for every
  view).
* Views use the existing `.print-area` / `.no-print` styles; printing shows the
  current page only. No bulk export is introduced in this phase.
* Tests: `tests/Feature/Transport/TransportReportTest.php` covers RBAC
  separation, the seeded Super Admin / College Admin grants, the fixed six
  views and their filters, sidebar placement under REPORTS after Library
  Reports, tenant isolation with forged foreign filter IDs, every
  relationship, soft deletes, pagination, empty / invalid / irrelevant filters,
  the GET-only read-only contract and the query-count guard.
  `TransportNavigationTest` pins the sidebar movement.
