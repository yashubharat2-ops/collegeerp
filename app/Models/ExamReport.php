<?php

namespace App\Models;

/**
 * ExamReport — the read-only examination reporting screen (Examinations Phase 4).
 *
 * This is deliberately NOT an Eloquent model: there are no reporting tables
 * and no report rows. Every figure on the screen is aggregated live from
 * published ExamResult / ExamResultItem data (simple counts grouped by
 * examination, program, subject and result status) through ExamReportService.
 *
 * The marker class exists so the screen has its own authorization boundary:
 * ExamReportPolicy is registered against it and guards the single
 * `exam_reports.view` permission.
 */
final class ExamReport
{
}
