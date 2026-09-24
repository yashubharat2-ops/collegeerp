<?php

namespace App\Models;

/**
 * CommunicationReport — the read-only Communication Reports screen.
 *
 * Deliberately NOT an Eloquent model: there are no reporting tables. Every
 * figure is aggregated live from the existing notices, circulars,
 * communication_notifications and communication_logs records of the active
 * college (see CommunicationReportService).
 *
 * The marker class exists only to give the screen its own authorization
 * boundary: CommunicationReportPolicy guards `communication_reports.view`.
 */
class CommunicationReport {}
