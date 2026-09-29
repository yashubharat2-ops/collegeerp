<?php

namespace App\Models;

/**
 * CertificateReport — the read-only Certificate Reports screen.
 *
 * Deliberately NOT an Eloquent model: there are no reporting tables. Every
 * figure is aggregated live from the existing certificate_types,
 * certificate_templates, certificates, students, and student_enrollments
 * records of the active college (see CertificateReportService).
 *
 * The marker class exists only to give the screen its own authorization
 * boundary: CertificateReportPolicy guards `certificate_reports.view`.
 */
class CertificateReport {}
