<?php

namespace App\Providers;

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
        // Future modules will register their handlers into BulkActionRegistry here
        // e.g.:
        // $registry = $this->app->make(BulkActionRegistry::class);
        // $registry->register('students', 'export', StudentBulkExportHandler::class);
    }
}
