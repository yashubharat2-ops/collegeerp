<?php

use Database\Migrations\Support\ReconcileCertificateSchema;
use Illuminate\Database\Migrations\Migration;

require_once __DIR__.'/support/ReconcileCertificateSchema.php';

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        (new ReconcileCertificateSchema())->up();
    }

    public function down(): void
    {
        // A rollback cannot distinguish adopted legacy rows from new records safely.
        throw new RuntimeException('Certificate schema reconciliation is forward-only. Restore a verified backup to undo it; legacy certificate records will not be dropped.');
    }
};
