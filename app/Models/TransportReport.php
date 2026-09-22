<?php

namespace App\Models;

/**
 * TransportReport — the read-only Transport Reports screen.
 *
 * Deliberately NOT an Eloquent model: there are no report tables and no report
 * rows. Every figure is aggregated live from the existing Transport, Student
 * and Finance records (vehicles, vehicle_documents, transport_drivers,
 * transport_routes/stops, student_transport_assignments,
 * student_transport_fee_assignments, fee_payments), so no business fact is
 * duplicated and nothing on the screen can be edited.
 *
 * The marker class exists so the screen has its own authorization boundary:
 * TransportReportPolicy is registered against it and guards
 * `transport_reports.view`.
 */
final class TransportReport
{
}
