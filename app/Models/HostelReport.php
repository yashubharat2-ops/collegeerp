<?php

namespace App\Models;

/**
 * HostelReport — the read-only Hostel Reports screen (Phase 3).
 *
 * Deliberately NOT an Eloquent model: there are no report tables and no report
 * rows. Every figure is aggregated live from the existing Hostel, Student and
 * Finance records (hostels, buildings, rooms, beds, hostel_allocations,
 * hostel_attendances, hostel_fee_assignments and the shared fee_payments /
 * fee_refunds rows), so no business fact is duplicated and nothing on the
 * screen can be edited.
 *
 * The marker class exists so the screen has its own authorization boundary:
 * HostelReportPolicy is registered against it and guards hostel_reports.view.
 */
final class HostelReport
{
}
