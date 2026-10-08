<?php

namespace Tests\Feature\BulkAction;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\College;
use App\Models\LibraryFine;
use App\Models\LibraryMember;
use App\Models\LibraryRenewal;
use App\Models\LibraryTransaction;
use App\Support\BulkAction\BulkActionRegistry;
use App\Support\BulkAction\BulkExportHandler;
use Tests\Feature\Library\LibraryTestHelpers;
use Tests\TestCase;

/**
 * Library bulk actions — selection, export authorization, policy enforcement,
 * tenant isolation and the export-only guarantee.
 *
 * The Library family is deliberately EXPORT-ONLY, and these tests pin it down:
 * every Library module registers exactly one action (`export`), the CSV
 * endpoint re-queries the ticked ids inside the active college and
 * re-authorizes the module and the record, a hand-edited URL cannot widen a
 * download, and nothing bulk-issues, returns, renews, assesses or pays.
 */
class LibraryBulkActionTest extends TestCase
{
    use LibraryTestHelpers;

    /**
     * Every Library module, with the model its export handler operates on, the
     * permission that gates it and the per-record policy ability.
     *
     * @return array<string, array{model: class-string, permission: string, policy: ?string}>
     */
    private function libraryModules(): array
    {
        return [
            'books' => ['model' => Book::class, 'permission' => 'books.view', 'policy' => 'view'],
            'book_copies' => ['model' => BookCopy::class, 'permission' => 'book_copies.view', 'policy' => 'view'],
            'library_members' => ['model' => LibraryMember::class, 'permission' => 'library_members.view', 'policy' => 'view'],
            'library_transactions' => ['model' => LibraryTransaction::class, 'permission' => 'library_transactions.view', 'policy' => 'view'],
            'library_renewals' => ['model' => LibraryRenewal::class, 'permission' => 'library_renewals.view', 'policy' => 'view'],
            'library_fines' => ['model' => LibraryFine::class, 'permission' => 'library_fines.view', 'policy' => 'view'],
        ];
    }

    public function test_every_library_bulk_module_registers_export_only(): void
    {
        $registry = app(BulkActionRegistry::class);

        foreach ($this->libraryModules() as $module => $spec) {
            $this->assertSame(
                ['export'],
                array_keys($registry->getForModule($module)),
                "Library module [{$module}] must expose the export action and nothing else."
            );

            $handler = $registry->get($module, 'export');

            $this->assertInstanceOf(BulkExportHandler::class, $handler, $module);
            $this->assertSame($spec['model'], $handler->modelClass(), $module);
            $this->assertSame($spec['permission'], $handler->requiredPermission(), $module);
            $this->assertSame($spec['policy'], $handler->policyAbility(), $module);
        }
    }

    public function test_library_listings_expose_bulk_selection_controls(): void
    {
        $college = $this->makeCollege('LIBUI');
        $librarian = $this->makeUserWithPermissions($college, [
            'books.view', 'book_copies.view', 'library_members.view',
            'library_transactions.view', 'library_renewals.view', 'library_fines.view',
        ]);

        $book = $this->makeBook($college, ['code' => 'BK-UI-001']);
        $copy = $this->makeBookCopy($college, $book, ['accession_number' => 'ACC-UI-001']);
        [$student, $enrollment] = $this->makeLibraryEnrollment($college);
        $member = $this->makeLibraryMember($college, $enrollment, ['member_code' => 'LM-UI-001']);
        $transaction = $this->makeIssuedTransaction($college, $copy, $member);
        $renewal = LibraryRenewal::create([
            'college_id' => $college->id,
            'issue_transaction_id' => $transaction->id,
            'old_due_date' => $transaction->due_on,
            'new_due_date' => $transaction->due_on->copy()->addDays(7),
            'renewed_on' => now()->toDateString(),
            'renewed_by' => $librarian->id,
        ]);
        $fine = LibraryFine::create([
            'college_id' => $college->id,
            'library_transaction_id' => $transaction->id,
            'library_member_id' => $member->id,
            'type' => 'overdue',
            'period_start' => $transaction->due_on,
            'period_end' => now()->toDateString(),
            'days_overdue' => 3,
            'rate_per_day' => 10,
            'assessed_amount' => 30,
            'paid_amount' => 0,
            'status' => 'pending',
        ]);

        $pages = [
            ['route' => 'books.index', 'module' => 'books', 'needle' => 'BK-UI-001'],
            ['route' => 'book-copies.index', 'module' => 'book_copies', 'needle' => 'ACC-UI-001'],
            ['route' => 'library-members.index', 'module' => 'library_members', 'needle' => 'LM-UI-001'],
            ['route' => 'library-transactions.index', 'module' => 'library_transactions', 'needle' => 'ACC-UI-001'],
            ['route' => 'library-renewals.index', 'module' => 'library_renewals', 'needle' => 'ACC-UI-001'],
            ['route' => 'library-fines.index', 'module' => 'library_fines', 'needle' => 'LM-UI-001'],
        ];

        foreach ($pages as $page) {
            $response = $this->asCollege($college, $librarian)->get(route($page['route']))->assertOk();

            $response->assertSee('data-bulk-selection', false);
            $response->assertSee('data-module="'.$page['module'].'"', false);
            $response->assertSee('data-select-all', false);
            $response->assertSee('data-select-row', false);
            $response->assertSee('data-bulk-action="export"', false);
            $response->assertSee($page['needle']);
        }
    }

    public function test_library_export_requires_the_module_permission(): void
    {
        $college = $this->makeCollege('LIBPERM');
        $outsider = $this->makeUserWithPermissions($college, []);
        $book = $this->makeBook($college, ['code' => 'BK-PERM-001']);

        $this->asCollege($college, $outsider)->postJson(route('bulk-actions.execute'), [
            'module' => 'books',
            'action' => 'export',
            'ids' => [$book->id],
        ])->assertForbidden();

        $this->asCollege($college, $outsider)
            ->get(route('books.export', ['ids' => [$book->id]]))
            ->assertForbidden();
    }

    public function test_library_export_re_queries_ids_inside_the_active_college(): void
    {
        $collegeA = $this->makeCollege('LIBA');
        $collegeB = $this->makeCollege('LIBB');
        $librarian = $this->makeUserWithPermissions($collegeA, ['books.view']);

        $mine = $this->makeBook($collegeA, ['code' => 'BK-MINE-001']);
        $foreign = $this->makeBook($collegeB, ['code' => 'BK-FOREIGN-001']);

        $response = $this->asCollege($collegeA, $librarian)->postJson(route('bulk-actions.execute'), [
            'module' => 'books',
            'action' => 'export',
            'ids' => [$mine->id, $foreign->id],
        ])->assertOk();

        $this->assertSame([$mine->id], $response->json('data.ids'));
        $this->assertSame(1, $response->json('skipped_unauthorized'));

        $redirect = $response->json('data.redirect');
        $this->assertIsString($redirect, 'The bulk endpoint must hand back a redirect to the CSV endpoint.');

        $csv = $this->asCollege($collegeA, $librarian)->get($redirect)->assertOk();
        $this->assertStringContainsString('books-export-', (string) $csv->headers->get('Content-Disposition'));

        $body = $csv->streamedContent();
        $this->assertStringContainsString('BK-MINE-001', $body);
        $this->assertStringNotContainsString('BK-FOREIGN-001', $body);
    }

    public function test_library_deleted_and_nonexistent_ids_are_skipped(): void
    {
        $college = $this->makeCollege('LIBDEL');
        $librarian = $this->makeUserWithPermissions($college, ['book_copies.view']);

        $live = $this->makeBookCopy($college, null, ['accession_number' => 'ACC-LIVE-001']);
        $deleted = $this->makeBookCopy($college, null, ['accession_number' => 'ACC-GONE-001']);
        $deleted->delete();

        $response = $this->asCollege($college, $librarian)->postJson(route('bulk-actions.execute'), [
            'module' => 'book_copies',
            'action' => 'export',
            'ids' => [$live->id, $deleted->id, 999999],
        ])->assertOk();

        $this->assertSame([$live->id], $response->json('data.ids'));
        $this->assertSame(2, $response->json('skipped_unauthorized'));

        $csv = $this->asCollege($college, $librarian)->get($response->json('data.redirect'))->assertOk();
        $body = $csv->streamedContent();

        $this->assertStringContainsString('ACC-LIVE-001', $body);
        $this->assertStringNotContainsString('ACC-GONE-001', $body);
    }

    public function test_book_copy_export_streams_a_bom_prefixed_csv(): void
    {
        $college = $this->makeCollege('LIBCSV');
        $librarian = $this->makeUserWithPermissions($college, ['book_copies.view']);
        $copy = $this->makeBookCopy($college, null, ['accession_number' => 'ACC-CSV-001', 'barcode' => 'BC-CSV-001']);

        $response = $this->asCollege($college, $librarian)->postJson(route('bulk-actions.execute'), [
            'module' => 'book_copies',
            'action' => 'export',
            'ids' => [$copy->id],
        ])->assertOk();

        $csv = $this->asCollege($college, $librarian)->get($response->json('data.redirect'));

        $csv->assertOk();
        $this->assertStringContainsString('attachment', (string) $csv->headers->get('Content-Disposition'));
        $this->assertStringContainsString('book-copies-export-', (string) $csv->headers->get('Content-Disposition'));
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));

        $body = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'Every CSV download leads with the UTF-8 BOM.');
        $this->assertStringContainsString('ACC-CSV-001', $body);
        $this->assertStringContainsString('BC-CSV-001', $body);
    }

    public function test_library_exports_never_mutate_circulation(): void
    {
        $college = $this->makeCollege('LIBNOMUT');
        $librarian = $this->makeUserWithPermissions($college, [
            'books.view', 'book_copies.view', 'library_members.view',
            'library_transactions.view', 'library_renewals.view', 'library_fines.view',
        ]);

        $book = $this->makeBook($college, ['code' => 'BK-NOMUT-001']);
        $copy = $this->makeBookCopy($college, $book, ['accession_number' => 'ACC-NOMUT-001']);
        [, $enrollment] = $this->makeLibraryEnrollment($college);
        $member = $this->makeLibraryMember($college, $enrollment, ['member_code' => 'LM-NOMUT-001']);
        $transaction = $this->makeIssuedTransaction($college, $copy, $member);
        $fine = LibraryFine::create([
            'college_id' => $college->id,
            'library_transaction_id' => $transaction->id,
            'library_member_id' => $member->id,
            'type' => 'overdue',
            'period_start' => $transaction->due_on,
            'period_end' => now()->toDateString(),
            'days_overdue' => 5,
            'rate_per_day' => 10,
            'assessed_amount' => 50,
            'paid_amount' => 0,
            'status' => 'pending',
        ]);

        $before = [
            'book' => $book->fresh()->getAttributes(),
            'copy' => $copy->fresh()->getAttributes(),
            'member' => $member->fresh()->getAttributes(),
            'transaction' => $transaction->fresh()->getAttributes(),
            'fine' => $fine->fresh()->getAttributes(),
        ];

        foreach ([
            ['books', $book->id],
            ['book_copies', $copy->id],
            ['library_members', $member->id],
            ['library_transactions', $transaction->id],
            ['library_fines', $fine->id],
        ] as [$module, $id]) {
            $response = $this->asCollege($college, $librarian)->postJson(route('bulk-actions.execute'), [
                'module' => $module,
                'action' => 'export',
                'ids' => [$id],
            ])->assertOk();

            $this->asCollege($college, $librarian)->get($response->json('data.redirect'))->assertOk();
        }

        // Read-only by construction: no issue, no return, no renewal, no fine
        // assessment / payment, no status change on any record.
        $this->assertSame($before['book'], $book->fresh()->getAttributes());
        $this->assertSame($before['copy'], $copy->fresh()->getAttributes());
        $this->assertSame($before['member'], $member->fresh()->getAttributes());
        $this->assertSame($before['transaction'], $transaction->fresh()->getAttributes());
        $this->assertSame($before['fine'], $fine->fresh()->getAttributes());
        $this->assertSame(BookCopy::STATUS_ISSUED, $copy->fresh()->status);
        $this->assertSame(LibraryTransaction::STATUS_ISSUED, $transaction->fresh()->status);
        $this->assertSame('pending', $fine->fresh()->status);
        $this->assertSame(0, (float) $fine->fresh()->paid_amount);
    }
}
