<?php

use Database\Migrations\Support\ReconcileCertificateSchema;
use Illuminate\Database\Migrations\Migration;

require_once __DIR__.'/support/ReconcileCertificateSchema.php';

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // Also covers installations where the original create migration was already applied.
        (new ReconcileCertificateSchema())->up();
    }

    public function down(): void
    {
        throw new RuntimeException('Certificate schema reconciliation is forward-only. Restore a verified backup to undo it; legacy certificate records will not be dropped.');
    }
};
