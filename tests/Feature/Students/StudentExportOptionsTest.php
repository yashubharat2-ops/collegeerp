<?php

namespace Tests\Feature\Students;

use App\Models\College;
use App\Models\Student;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Student list's Export menu: Excel, PDF and Print.
 *
 * What is under test here is the SHAPE of the feature, because the security of
 * each format is the security of the export it already had:
 *
 *  - one dropdown, in the page header AND in the bulk selection bar, offering the
 *    three formats; Excel stays the existing Excel-compatible CSV endpoint,
 *  - the header menu exports the current filtered/sorted list (its links carry
 *    the query string, minus `page`),
 *  - the bulk menu exports the ticked rows: every option is a registered bulk
 *    action, so the ids are re-queried inside the college scope, re-authorized
 *    per record, and only the survivors travel in the follow-up URL,
 *  - PDF and Print are the same server-rendered A4 report (no PDF library in this
 *    project): the PDF variant for review-then-save-as-PDF, the Print variant with
 *    the native dialog open. Both are behind `students.export` + `students.view`
 *    and both are tenant-scoped.
 */
class StudentExportOptionsTest extends TestCase
{
    use StudentTestHelpers;

    /**
     * @return array{0: College, 1: User}
     */
    private function collegeWithExporter(string $code, array $permissions = ['students.view', 'students.export']): array
    {
        $college = $this->makeCollege($code);

        return [$college, $this->makeUserWithPermissions($college, $permissions)];
    }

    private function list(College $college, User $user, array $query = []): TestResponse
    {
        return $this->asCollege($college, $user)->get(route('students.index', $query));
    }

    private function report(College $college, User $user, string $format, array $query = []): TestResponse
    {
        $route = $format === 'print' ? 'students.export.print' : 'students.export.pdf';

        return $this->asCollege($college, $user)->get(route($route, $query));
    }

    /**
     * Assert the follow-up URL a bulk action produced, and return its ids.
     *
     * @return array<int, int>
     */
    private function idsFromRedirect(TestResponse $response, string $routeName): array
    {
        $response->assertRedirect();
        $target = (string) $response->headers->get('Location');

        $this->assertStringStartsWith(route($routeName), $target, "Expected a redirect to {$routeName}.");

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        return array_map('intval', (array) ($query['ids'] ?? []));
    }

    /**
     * The student numbers of the printable report, in the order its rows were
     * RENDERED.
     *
     * Read from the report's own `<tbody>`, never from the whole page: the toolbar,
     * the heading and the links carry filters, a sort key and counts — not student
     * data — so scoping here is what makes a row-order assertion mean what it says.
     */
    private function printedStudentNumbers(string $html): array
    {
        $bodyStart = strpos($html, '<tbody>');
        $bodyEnd = strpos($html, '</tbody>', (int) $bodyStart);

        $this->assertNotFalse($bodyStart, 'The report has no table body.');
        $this->assertNotFalse($bodyEnd, 'The report table body is not closed.');

        $rows = substr($html, $bodyStart, $bodyEnd - $bodyStart);

        preg_match_all('/STU-[A-Z0-9]+/', $rows, $matches);

        return $matches[0];
    }

    public function test_the_page_header_offers_one_export_menu_with_the_three_formats(): void
    {
        [$college, $exporter] = $this->collegeWithExporter('SEO1');
        $this->makeStudent($college, ['student_number' => 'STU-MENU']);

        $html = $this->list($college, $exporter)->assertOk()->getContent();

        // One dropdown shell, with a trigger and a panel the shared script toggles.
        $this->assertStringContainsString('data-dropdown', $html);
        $this->assertStringContainsString('data-dropdown-trigger', $html);
        $this->assertStringContainsString('data-dropdown-menu', $html);

        // The trigger is the shared indigo button — no second button colour.
        $this->assertStringContainsString('class="button inline-flex items-center gap-1.5"', $html);
        $this->assertStringNotContainsString('!bg-slate-700 hover:!bg-slate-800', $html);

        // The three formats, as plain links to the three export endpoints.
        foreach (['students.export', 'students.export.pdf', 'students.export.print'] as $name) {
            $this->assertStringContainsString('href="'.e(route($name)).'"', $html);
        }

        $this->assertStringContainsString('>Excel</a>', $html);
        $this->assertStringContainsString('>PDF</a>', $html);
        $this->assertStringContainsString('>Print</a>', $html);
    }

    public function test_the_header_menu_carries_the_active_filters_and_sort_but_never_the_page(): void
    {
        [$college, $exporter] = $this->collegeWithExporter('SEO2');
        $this->makeStudent($college, ['student_number' => 'STU-QUERY']);

        $query = ['status' => 'active', 'sort' => 'student_number', 'direction' => 'desc'];
        $html = $this->list($college, $exporter, $query + ['page' => 3])->assertOk()->getContent();

        // Every format keeps the filter/sort context, so the file/report matches
        // what the user is looking at.
        foreach (['students.export', 'students.export.pdf', 'students.export.print'] as $name) {
            $this->assertStringContainsString('href="'.e(route($name, $query)).'"', $html);
        }

        // ...while pagination is a screen concern and is dropped: no export link
        // may carry `page`, or "export" would silently mean "page 3 only".
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*students\/export[^"]*page=/', $html);
    }

    public function test_the_bulk_bar_offers_the_same_three_formats_as_registered_actions(): void
    {
        [$college, $exporter] = $this->collegeWithExporter('SEO3', [
            'students.view', 'students.export', 'student_id_cards.generate', 'student_documents.view',
        ]);
        $this->makeStudent($college, ['student_number' => 'STU-BARMENU']);

        $html = $this->list($college, $exporter)->assertOk()->getContent();

        // Each export option occurs EXACTLY ONCE on the page. The page-header menu
        // renders the same formats, but as plain <a href> links with no data
        // attribute, so the bar is the only place these action names exist — an
        // occurrence elsewhere can neither satisfy nor break the ordering below.
        foreach (['data-bulk-action="export"', 'data-bulk-action="export_pdf"', 'data-bulk-action="export_print"'] as $token) {
            $this->assertSame(1, substr_count($html, $token), "Expected exactly one {$token} on the page.");
        }

        // Isolate the bar ITSELF before ordering anything: it runs from its own
        // container down to the table it drives (the table's select-all checkbox
        // is the first thing rendered inside that table). Every position below is
        // read from this slice, never from the page.
        $barStart = strpos($html, 'data-bulk-selection');
        $tableStart = strpos($html, 'data-select-all', (int) $barStart);

        $this->assertNotFalse($barStart, 'The bulk selection bar is missing.');
        $this->assertNotFalse($tableStart, 'The table that the bulk bar drives is missing.');
        $this->assertTrue($barStart < $tableStart, 'The bulk bar must stay above the table it drives.');

        $bar = substr($html, $barStart, $tableStart - $barStart);

        // The three formats are bulk actions INSIDE the bar, in this order:
        // Excel → PDF → Print. Read in document order, so the list itself is the
        // proof of the ordering.
        preg_match_all('/data-bulk-action="([^"]+)"/', $bar, $matches);
        $actions = $matches[1] ?? [];

        $this->assertSame(
            ['export', 'export_pdf', 'export_print', 'id_cards', 'documents'],
            $actions,
            'The bulk bar must offer Export (Excel, PDF, Print), Generate ID cards and Bulk documents, in that order.'
        );

        // The Excel → PDF → Print order stated explicitly, pairwise. Written as
        // plain comparisons on purpose: assertLessThan()'s argument order is
        // (expected, actual), so "comes first" is easy to invert by accident.
        $position = array_flip($actions);
        $this->assertTrue($position['export'] < $position['export_pdf'], 'Excel must be listed before PDF.');
        $this->assertTrue($position['export_pdf'] < $position['export_print'], 'PDF must be listed before Print.');

        // The two unchanged actions are still there, untouched.
        $this->assertStringContainsString('data-bulk-action="id_cards"', $bar);
        $this->assertStringContainsString('data-bulk-action="documents"', $bar);

        // And the shared scripts that make the menu and the printing work are
        // loaded as module scripts (the only form the layout allows).
        $this->assertStringContainsString('src="'.asset('js/erp-dropdown.js').'"', $html);
    }

    public function test_the_export_menu_is_hidden_without_the_export_permission(): void
    {
        [$college] = $this->collegeWithExporter('SEO4');
        $this->makeStudent($college, ['student_number' => 'STU-NOPERM']);

        // A user who may browse the list, and even generate cards, but may not
        // export: no export menu anywhere, while the other actions keep their own
        // independent gates.
        $html = $this->list($college, $this->makeUserWithPermissions($college, [
            'students.view', 'student_id_cards.generate',
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-dropdown', $html);
        $this->assertStringNotContainsString('data-bulk-action="export"', $html);
        $this->assertStringNotContainsString('data-bulk-action="export_pdf"', $html);
        $this->assertStringNotContainsString('data-bulk-action="export_print"', $html);
        $this->assertStringNotContainsString('href="'.route('students.export.pdf').'"', $html);

        $this->assertStringContainsString('data-bulk-action="id_cards"', $html);
    }

    public function test_the_pdf_and_print_reports_demand_the_export_permission(): void
    {
        [$college] = $this->collegeWithExporter('SEO5');
        $this->makeStudent($college, ['student_number' => 'STU-REPORTGUARD']);

        // Checked FIRST: the reports sit behind `auth`, so the guest request must
        // be made before any actingAs() call authenticates the test.
        $this->get(route('students.export.pdf'))->assertRedirect(route('login'));
        $this->get(route('students.export.print'))->assertRedirect(route('login'));

        $viewer = $this->makeUserWithPermissions($college, ['students.view']);
        $exporter = $this->makeUserWithPermissions($college, ['students.view', 'students.export']);

        foreach (['pdf', 'print'] as $format) {
            $this->report($college, $viewer, $format)->assertForbidden();
            $this->report($college, $exporter, $format)->assertOk();
        }
    }

    public function test_the_pdf_report_is_a_print_safe_a4_document_with_the_filtered_rows(): void
    {
        [$college, $exporter] = $this->collegeWithExporter('SEO6');
        $year = $this->makeYear($college, '2026', '2026-27');
        $program = $this->makeProgram($college, 'BSC');

        $student = $this->makeStudent($college, [
            'student_number' => 'STU-PDF-1',
            'first_name' => 'Report',
            'last_name' => 'Candidate',
            'email' => 'report.candidate@example.test',
            'phone' => '9000000009',
            'category' => 'sc',
            'gender' => 'female',
            'admission_date' => '2026-07-05',
        ]);
        $this->makeEnrollment($college, $student, $year, $program, ['enrollment_number' => 'ENR-PDF-1']);

        $html = $this->report($college, $exporter, 'pdf')->assertOk()->getContent();

        // A printable region, and the A4 report styling that keeps multi-page runs
        // readable (the print rules themselves live in resources/css/app.css).
        $this->assertStringContainsString('print-area', $html);
        $this->assertStringContainsString('print-report', $html);
        $this->assertStringContainsString('<thead>', $html);

        // The PDF variant does NOT open a dialog by itself: the user reviews the
        // report first and then prints or saves it as a PDF.
        $this->assertStringContainsString('data-auto-print="0"', $html);
        $this->assertStringNotContainsString('data-auto-print="1"', $html);
        $this->assertStringContainsString('data-print-now', $html);

        // The document header (tenant name, scope, count) and the student row.
        $this->assertStringContainsString($college->name, $html);
        $this->assertStringContainsString('Filtered student list — 1 of 1 student', $html);
        $this->assertStringContainsString('STU-PDF-1', $html);
        $this->assertStringContainsString('Report', $html);
        $this->assertStringContainsString('ENR-PDF-1', $html);
        $this->assertStringContainsString('2026-27', $html);
        $this->assertStringContainsString('BSc', $html);

        // The report is audited as an export, like the CSV.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'students.exported',
            'college_id' => $college->id,
        ]);
    }

    public function test_the_print_option_opens_the_native_print_dialog_on_the_same_report(): void
    {
        [$college, $exporter] = $this->collegeWithExporter('SEO7');
        $this->makeStudent($college, ['student_number' => 'STU-PRINT-1']);

        $html = $this->report($college, $exporter, 'print')->assertOk()->getContent();

        // Same document as the PDF variant (so the printed/saved output is the
        // saved report), with the auto-print flag set: js/erp-print.js opens
        // window.print() once the page has loaded.
        $this->assertStringContainsString('print-area', $html);
        $this->assertStringContainsString('print-report', $html);
        $this->assertStringContainsString('data-auto-print="1"', $html);
        $this->assertStringContainsString('STU-PRINT-1', $html);
        $this->assertStringContainsString('src="'.asset('js/erp-print.js').'"', $html);

        // The PDF variant of the same page must never carry the flag.
        $this->assertStringNotContainsString('data-auto-print="1"', $this->report($college, $exporter, 'pdf')->getContent());
    }

    public function test_the_reports_respect_the_filters_and_the_chosen_sort(): void
    {
        [$college, $exporter] = $this->collegeWithExporter('SEO8');
        $year = $this->makeYear($college, '2026', '2026-27');
        $program = $this->makeProgram($college, 'BSC');

        $alpha = $this->makeStudent($college, ['first_name' => 'Alpha', 'student_number' => 'STU-A', 'status' => 'active']);
        $beta = $this->makeStudent($college, ['first_name' => 'Beta', 'student_number' => 'STU-B', 'status' => 'active']);
        $gone = $this->makeStudent($college, ['first_name' => 'Gamma', 'student_number' => 'STU-C', 'status' => 'graduated']);
        $this->makeEnrollment($college, $alpha, $year, $program);
        $this->makeEnrollment($college, $beta, $year, $program);
        $this->makeEnrollment($college, $gone, $year, $program);

        // A status filter applies to the report exactly as it does to the list.
        // Read the PRINTED ROWS (the report's own <tbody>), not the page: the
        // toolbar and links carry filters and sort keys, never student data.
        $html = $this->report($college, $exporter, 'pdf', ['status' => 'active'])->assertOk()->getContent();

        $this->assertSame(['STU-A', 'STU-B'], $this->printedStudentNumbers($html));
        // The filtered-out student must not appear anywhere on the report, not
        // merely be absent from the rows.
        $this->assertStringNotContainsString('STU-C', $html, 'A student excluded by the filter must not appear on the report.');
        $this->assertStringContainsString('2 of 2 students', $html);

        // And the on-screen sort is honoured — the CSV stream cannot do this (it
        // pages by primary key), the report can, so descending really is descending.
        $html = $this->report($college, $exporter, 'print', [
            'sort' => 'student_number',
            'direction' => 'desc',
        ])->assertOk()->getContent();

        // Each number occurs exactly once on the page, so a token outside the
        // table cannot stand in for a row (and a mis-sorted row cannot hide).
        foreach (['STU-A', 'STU-B', 'STU-C'] as $number) {
            $this->assertSame(1, substr_count($html, $number), "Expected {$number} to appear exactly once on the page.");
        }

        // The rows, in the order they were rendered: student number descending.
        $printed = $this->printedStudentNumbers($html);

        $this->assertSame(
            ['STU-C', 'STU-B', 'STU-A'],
            $printed,
            'The report rows must follow the chosen sort: student number, descending.'
        );

        // C → B → A stated explicitly, pairwise, on the row positions. Written as
        // plain comparisons on purpose: assertLessThan()'s argument order is
        // (expected, actual), so "comes first" is easy to invert by accident.
        $position = array_flip($printed);
        $this->assertTrue($position['STU-C'] < $position['STU-B'], 'C must be printed before B.');
        $this->assertTrue($position['STU-B'] < $position['STU-A'], 'B must be printed before A.');
    }

    public function test_a_selected_report_contains_only_the_requested_students(): void
    {
        [$college, $exporter] = $this->collegeWithExporter('SEO9');
        $collegeB = $this->makeCollege('SEO9B');

        $selected = $this->makeStudent($college, ['first_name' => 'Chosen', 'student_number' => 'STU-CHOSEN']);
        $other = $this->makeStudent($college, ['first_name' => 'Ignored', 'student_number' => 'STU-IGNORED']);
        $foreign = $this->makeStudent($collegeB, ['first_name' => 'Foreign', 'student_number' => 'STU-FOREIGNREPORT']);

        // Own selection + a foreign college's id + junk: the report narrows to the
        // authorized student, exactly like the CSV export.
        $html = $this->report($college, $exporter, 'pdf', [
            'ids' => [$selected->id, $foreign->id, 'DROP TABLE students', '999999'],
        ])->assertOk()->getContent();

        $this->assertStringContainsString('STU-CHOSEN', $html);
        $this->assertStringContainsString('Selected students', $html);
        $this->assertStringNotContainsString('STU-IGNORED', $html);
        $this->assertStringNotContainsString('STU-FOREIGNREPORT', $html);

        // A hand-edited URL carrying only a foreign id has nothing to render, and
        // must never fall back to the full list.
        $html = $this->report($college, $exporter, 'print', ['ids' => [$foreign->id]])->assertOk()->getContent();
        $this->assertStringNotContainsString('STU-FOREIGNREPORT', $html);
        $this->assertStringNotContainsString('STU-CHOSEN', $html);
        $this->assertStringContainsString('No students match the current filters or selection.', $html);
    }

    public function test_the_reports_are_tenant_scoped(): void
    {
        [$collegeA, $exporter] = $this->collegeWithExporter('SEO10A');
        $collegeB = $this->makeCollege('SEO10B');

        $this->makeStudent($collegeA, ['first_name' => 'Local', 'student_number' => 'STU-LOCALREPORT']);
        $this->makeStudent($collegeB, ['first_name' => 'Foreign', 'student_number' => 'STU-OTHERCOLLEGE']);

        foreach (['pdf', 'print'] as $format) {
            $html = $this->report($collegeA, $exporter, $format)->assertOk()->getContent();

            $this->assertStringContainsString('STU-LOCALREPORT', $html);
            $this->assertStringNotContainsString('STU-OTHERCOLLEGE', $html);
            $this->assertStringNotContainsString($collegeB->name, $html);
        }
    }

    public function test_bulk_pdf_and_print_open_the_report_for_the_authorized_selection_only(): void
    {
        [$college, $operator] = $this->collegeWithExporter('SEO11');
        $collegeB = $this->makeCollege('SEO11B');

        $before = Student::withoutGlobalScopes()->count();

        $mine = $this->makeStudent($college, ['first_name' => 'Mine', 'student_number' => 'STU-BULKPDF']);
        $deleted = $this->makeStudent($college, ['first_name' => 'Deleted', 'student_number' => 'STU-BULKDEL']);
        $deleted->delete();
        $foreign = $this->makeStudent($collegeB, ['first_name' => 'Foreign', 'student_number' => 'STU-BULKFOREIGN']);

        foreach (['export_pdf' => 'students.export.pdf', 'export_print' => 'students.export.print'] as $action => $routeName) {
            $response = $this->asCollege($college, $operator)->post(route('bulk-actions.execute'), [
                'module' => 'students',
                'action' => $action,
                'ids' => [$mine->id, $deleted->id, $foreign->id, '999999'],
            ]);

            // Only the authorized id survives into the URL the browser follows.
            $this->assertSame([$mine->id], $this->idsFromRedirect($response, $routeName));

            $html = $this->report($college, $operator, $action === 'export_print' ? 'print' : 'pdf', ['ids' => [$mine->id]])
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('STU-BULKPDF', $html);
            $this->assertStringNotContainsString('STU-BULKDEL', $html);
            $this->assertStringNotContainsString('STU-BULKFOREIGN', $html);
        }

        // Nothing was created: the reports are views of existing data, and the
        // soft-deleted student is still only soft-deleted.
        $this->assertSame($before + 3, Student::withoutGlobalScopes()->count());
        $this->assertSoftDeleted('students', ['id' => $deleted->id]);
    }

    public function test_bulk_pdf_and_print_demand_the_export_permission(): void
    {
        [$college] = $this->collegeWithExporter('SEO12');
        $student = $this->makeStudent($college, ['student_number' => 'STU-BULKGUARD']);

        $viewer = $this->makeUserWithPermissions($college, ['students.view']);

        foreach (['export_pdf', 'export_print'] as $action) {
            $this->asCollege($college, $viewer)
                ->post(route('bulk-actions.execute'), [
                    'module' => 'students',
                    'action' => $action,
                    'ids' => [$student->id],
                ])
                ->assertRedirect()
                ->assertSessionHasErrors('bulk');
        }
    }
}
