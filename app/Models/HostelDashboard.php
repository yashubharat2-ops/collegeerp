<?php

namespace App\Models;

/**
 * HostelDashboard — the read-only Hostel Management overview screen.
 *
 * Deliberately NOT an Eloquent model: there are no dashboard tables and no
 * summary rows. Every figure is aggregated live from the Phase 1 masters
 * (hostels, hostel_buildings, hostel_rooms, hostel_beds), so nothing is
 * duplicated and numbers can never drift from the records.
 *
 * The marker class exists so the screen has its own authorization boundary:
 * HostelDashboardPolicy is registered against it and guards
 * `hostel_dashboard.view`.
 */
class HostelDashboard
{
}
