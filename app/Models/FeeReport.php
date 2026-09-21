<?php

namespace App\Models;

/**
 * FeeReport — the read-only Finance / Fees reporting screen.
 *
 * Deliberately NOT an Eloquent model: there are no reporting tables and no
 * report rows. Every figure is aggregated live from the existing transactional
 * records (student_fee_assignments, fee_payments, fee_concessions, fee_refunds),
 * so no financial fact is duplicated.
 *
 * The marker class exists so the screen has its own authorization boundary:
 * FeeReportPolicy is registered against it and guards `fee_reports.view`.
 */
final class FeeReport
{
}
