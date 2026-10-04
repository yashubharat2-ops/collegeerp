<?php

namespace App\Providers;

use App\Domain\Student\BulkActions\StudentBulkDocumentHandler;
use App\Domain\Student\BulkActions\StudentBulkExportHandler;
use App\Domain\Student\BulkActions\StudentBulkIdCardHandler;
use App\Domain\Student\BulkActions\StudentBulkPdfHandler;
use App\Domain\Student\BulkActions\StudentBulkPrintHandler;
use App\Support\BulkAction\BulkActionRegistry;
use Illuminate\Support\ServiceProvider;

class BulkActionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BulkActionRegistry::class, function () {
            return new BulkActionRegistry();
        });
    }

    public function boot(): void
    {
        $registry = $this->app->make(BulkActionRegistry::class);

        // Students — the first module to register handlers. `module` + `action`
        // are the two validated keys of the shared bulk action endpoint; the
        // action names are exactly what the listing's bulk bar posts.
        $registry
            ->register('students', 'export', StudentBulkExportHandler::class)
            ->register('students', 'id_cards', StudentBulkIdCardHandler::class)
            ->register('students', 'documents', StudentBulkDocumentHandler::class)
            // The Export menu's two report formats (the printable A4 student
            // list). Same permission, same server-side id re-query and same
            // per-record policy as `export` — only the destination differs, so
            // they are separate actions rather than a parameter the bulk bar
            // cannot send.
            ->register('students', 'export_pdf', StudentBulkPdfHandler::class)
            ->register('students', 'export_print', StudentBulkPrintHandler::class);
    }
}
