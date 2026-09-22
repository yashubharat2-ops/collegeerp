<?php

namespace App\Http\Controllers;

use App\Domain\Library\Services\LibraryDashboardService;
use App\Models\LibraryDashboard;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Library Dashboard (Library Management).
 *
 * Read-only overview of the Phase 1 masters — books, categories, authors and
 * publishers. Every figure is aggregated live through the tenant-scoped models
 * by LibraryDashboardService; there are no dashboard tables and nothing on the
 * screen writes. Access is gated by `library_dashboard.view`.
 */
class LibraryDashboardController extends Controller
{
    public function __invoke(Request $request, LibraryDashboardService $dashboard): View
    {
        $this->authorize('viewAny', LibraryDashboard::class);

        $totals = $dashboard->totals();

        return view('library_dashboard.index', [
            'totals' => $totals,
            'totalBooks' => $totals['books'],
            'totalCategories' => $totals['categories'],
            'totalAuthors' => $totals['authors'],
            'totalPublishers' => $totals['publishers'],
            'booksPerCategory' => $dashboard->booksPerCategory(),
            'topAuthors' => $dashboard->topAuthors(),
            'topPublishers' => $dashboard->topPublishers(),
            'booksPerLanguage' => $dashboard->booksPerLanguage(),
            'booksPerDecade' => $dashboard->booksPerDecade(),
            'recentBooks' => $dashboard->recentBooks(),
        ]);
    }
}
