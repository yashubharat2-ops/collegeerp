<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance / Fees — fee category master.
 *
 * A FeeCategory is a TENANT-SCOPED, college-owned classification of fee heads
 * (Tuition, Admission, Examination, Library, Transport, …). It exists so that
 * reporting and fee structures can group fee components without hard-coding any
 * institution's fee taxonomy in code.
 *
 * Additive only: nothing that Platform / Students / Academics / Examinations or
 * the Fee Structure foundation already owns is modified here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'name'], 'fee_categories_college_name_idx');
            $table->index(['college_id', 'status'], 'fee_categories_college_status_idx');
        });

        // At most one ACTIVE (not soft-deleted) category per (college, code).
        // Partial unique indexes are supported by SQLite/PostgreSQL; MySQL and
        // MariaDB cannot express "unique where not soft-deleted", so they rely on
        // the same application-level guard the rest of the project uses.
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX fee_categories_active_unique ON fee_categories (college_id, code) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_categories');
    }
};
