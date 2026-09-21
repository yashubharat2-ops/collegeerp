<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance / Fees — the individual fee components of a FeeStructure.
 *
 * One row per fee head (Tuition Fee, Admission Fee, Library Fee, …) with its own
 * amount. Names are configurable per college: nothing about a particular
 * institution's fee heads is expressed in code.
 *
 * Items follow their parent structure's lifecycle and are not soft-deleted
 * themselves, exactly like grade_scale_items, so the uniqueness below is a
 * plain unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_structure_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_structure_id')->constrained('fee_structures')->cascadeOnDelete();

            // Fee category / component name, e.g. "Tuition Fee".
            $table->string('name');

            // Money is stored as decimal, never as float (database conventions).
            // decimal(12,2) caps a single component at 9,999,999,999.99.
            $table->decimal('amount', 12, 2);

            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();

            // No duplicate fee head inside one structure.
            $table->unique(['fee_structure_id', 'name'], 'fee_structure_items_structure_name_unique');
            $table->index(['fee_structure_id', 'status', 'sort_order'], 'fee_structure_items_ordering_idx');
            $table->index(['college_id', 'fee_structure_id'], 'fee_structure_items_college_structure_idx');
        });

        // Amount must be non-negative. This is the third layer of the same rule
        // (Form Requests -> service -> database); SQLite cannot add a CHECK
        // constraint to an existing table with ALTER TABLE and in-memory test
        // databases are created through Laravel's Blueprint, so — like
        // academic_years' date rule — the constraint is added on the engines
        // that support it and the application layers cover SQLite.
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE `fee_structure_items` ADD CONSTRAINT `fee_structure_items_amount_non_negative` CHECK (`amount` >= 0)');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "fee_structure_items" ADD CONSTRAINT "fee_structure_items_amount_non_negative" CHECK ("amount" >= 0)');
        } elseif ($driver === 'sqlsrv') {
            DB::statement('ALTER TABLE [fee_structure_items] ADD CONSTRAINT [fee_structure_items_amount_non_negative] CHECK ([amount] >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_structure_items');
    }
};
