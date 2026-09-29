# Hostel Reports

Hostel Reports is a read-only reporting screen at `GET /hostel-reports` (`hostel-reports.index`). It presents live Hostel, Student, Academic and Finance data for the active college. It does not create report tables, copy business data, change operational workflows, or add export endpoints.

## Report list

The in-page report tabs are deliberately limited to these seven reports, in this order:

1. **Hostel / Building Report** — tenant-scoped hostel and building/block registers, stored status, and live room/bed child counts.
2. **Room / Bed Occupancy Report** — room and bed registers, occupied/available/inactive totals, resident information, and occupancy status.
3. **Hostel Allocation Report** — the existing allocation history, with active, vacated and cancelled counts.
4. **Hostel Attendance Report** — present/absent/leave totals, per-hostel aggregates, and the attendance register.
5. **Hostel Fee Report** — fee-assignment ledger and hostel totals for assigned, paid, refunded, net collected and outstanding amounts.
6. **Vacated Student Report** — existing vacated allocation history, including recorded allocation remarks when present.
7. **Hostel Summary** — live college-wide Hostel dashboard counts, vacated-student count, attendance totals and fee ledger totals.

## Authorization and navigation

The page uses the single permission `hostel_reports.view`, enforced by `HostelReportPolicy`. Existing Hostel permission seeding includes this view permission in the seeded Super Admin and College Admin grants. Operational Hostel permissions do not grant access to reports by themselves.

The only sidebar entry is **Hostel Reports** in the existing single **REPORTS** section, after Transport Reports. Hostel Management retains its operational links and does not contain the report entry.

## Data sources and tenant safety

- Hostel / building / room / bed registers use the existing `Hostel`, `HostelBuilding`, `HostelRoom` and `HostelBed` models and their live relationships/counts.
- Allocation history, occupancy and vacated history use `HostelAllocation`; occupancy is based on allocations rather than treating the bed status flag as a second occupancy source.
- Attendance uses existing `HostelAttendance` records and their linked allocation, enrollment and student.
- Fee totals use `HostelFeeAssignment` and `HostelFeeService::ledgerFor()`, which delegates payment/refund arithmetic to the shared Finance `FeeLedger`. Hostel fee report totals therefore follow the same paid, refund and outstanding definitions as the operational Hostel Fees screen; cancelled assignments are not included in payable totals.
- Hostel Summary reuses `HostelDashboardService::totals()` for the existing Hostel/bed counters. Its attendance and fee totals are aggregated live from their existing sources.

Eloquent queries retain college and soft-delete scopes. Query-builder attendance and vacated-student aggregates explicitly constrain each tenant-owned source row and ignore soft-deleted rows. Filter option lists are tenant-scoped as well. A foreign-college integer ID can be submitted as a filter, but it matches no current-college rows and never adds foreign values to the option lists. Inactive records remain visible with their stored status where applicable; soft-deleted records are excluded. Inactive beds are not counted as available.

## Filters

Only filters that a selected report can apply are shown. ID filters accept positive integers; dates use `YYYY-MM-DD`, and the end date cannot precede the start date.

| Report | Supported filters |
| --- | --- |
| Hostel / Building Report | Hostel, building/block |
| Room / Bed Occupancy Report | Academic year, academic term, hostel, building/block, room, bed, from/to allocation-overlap dates |
| Hostel Allocation Report | Academic year, academic term, hostel, building/block, room, bed, student, program, section, allocation status, from/to allocation dates |
| Hostel Attendance Report | Academic year, academic term, hostel, building/block, room, bed, student, program, section, attendance status, from/to attendance dates |
| Hostel Fee Report | Academic year, academic term, hostel, building/block, room, bed, student, program, section, fee-ledger status, from/to fee-assignment effective dates |
| Vacated Student Report | Academic year, academic term, hostel, building/block, room, bed, student, program, section, from/to vacated dates |
| Hostel Summary | No filters |

For occupancy, with no date window only current active allocations occupy beds. With a date window, the report includes non-cancelled allocations that overlap it; a vacated allocation is counted only when its recorded dates overlap. Fee date filters select assignments whose existing effective period overlaps the selected window; they do not calculate a historical payment balance. On the attendance report, the attendance-status filter narrows the register while the headline and hostel aggregates continue to show all valid statuses for the other selected filters.

## Pagination and ordering

Long registers are paginated at 15 rows per page and use stable tie-breakers (normally the record ID) for deterministic ordering. Hostel and building registers, room and bed registers, and the fee-assignment ledger use independent page parameters. Fee ledger rows are processed in bounded ID chunks and only the requested page is materialized, while the totals continue to use the shared ledger calculation.

## Tests

Focused feature coverage lives in `tests/Feature/Hostel/HostelReportTest.php`. The Hostel navigation tests also protect the single REPORTS section and its placement: `HostelNavigationTest`, `HostelPhase2NavigationTest` and `HostelPhase3NavigationTest`.
