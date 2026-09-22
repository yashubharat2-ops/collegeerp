<?php

namespace App\Models;

/**
 * LibraryDashboard — the read-only Library Management overview screen.
 *
 * Deliberately NOT an Eloquent model: there are no dashboard tables and no
 * summary rows. Every figure is aggregated live from the Phase 1 masters
 * (books, book_categories, authors, publishers), so nothing is duplicated.
 *
 * The marker class exists so the screen has its own authorization boundary:
 * LibraryDashboardPolicy is registered against it and guards
 * `library_dashboard.view`.
 */
final class LibraryDashboard
{
}
