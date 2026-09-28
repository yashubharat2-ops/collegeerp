<?php

namespace App\Models;

/**
 * FinanceReport — the read-only Finance Reports screen (REPORTS module).
 *
 * Deliberately NOT an Eloquent model: there are no reporting tables and no
 * report rows. Every figure is aggregated live from the existing Finance /
 * Fees records (fee_structures, student_fee_assignments, fee_payments,
 * fee_concessions, fee_refunds) plus the existing Transport and Hostel fee
 * assignments that share those same payment rows, so no financial fact is
 * ever duplicated.
 *
 * The marker class exists so the screen has its own authorization boundary:
 * FinanceReportPolicy is registered against it and guards
 * `finance_reports.view`, kept separate from every operational Finance / Fees
 * permission (and from the pre-existing fee_reports.view screen).
 */
final class FinanceReport
{
}
