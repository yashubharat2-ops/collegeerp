<?php

namespace App\Models;

/**
 * ConsolidatedReport — the read-only Consolidated Reports screen.
 *
 * Deliberately NOT an Eloquent model and NOT a table: the consolidated reports
 * create no reporting schema. Every figure is read live from the existing
 * Student, Academic, Examination, Finance, HR, Library, Transport, Hostel,
 * Inventory, Communication and Certificate records of the active college (see
 * ConsolidatedReportService).
 *
 * The marker class exists only to give the module its own authorization
 * boundary: ConsolidatedReportPolicy guards `consolidated_reports.view`.
 */
final class ConsolidatedReport
{
}
