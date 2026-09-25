<?php

namespace App\Models;

/**
 * CommunicationDashboard — the read-only Communication Management overview.
 *
 * Deliberately NOT an Eloquent model: there are no dashboard or summary
 * tables. Every figure is aggregated live from the notices, circulars and
 * communication_notifications records of the active college (see
 * CommunicationDashboardService), so numbers can never drift.
 *
 * The marker class exists only to give the screen its own authorization
 * boundary: CommunicationDashboardPolicy guards `communication_dashboard.view`.
 */
class CommunicationDashboard {}
