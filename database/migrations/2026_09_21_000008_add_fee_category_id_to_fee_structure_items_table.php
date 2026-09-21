<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance / Fees — optional FeeCategory reference on fee structure items.
 *
 * This is an ENHANCEMENT of the already implemented Fee Structure module, not a
 * rewrite: the fee head keeps its own free-text `name` (which stays the label
 * printed on a structure) and GAINS a nullable pointer to a fee category for
 * classification and reporting.
 *
 * Safety:
 * - nullable with nullOnDelete, so every existing fee_structure_items row keeps
 *   working unchanged and no production data is rewritten;
 * - no existing column, index or constraint is modified or dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_structure_items', function (Blueprint $table) {
            $table->foreignId('fee_category_id')
                ->nullable()
                ->after('fee_structure_id')
                ->constrained('fee_categories')
                ->nullOnDelete();

            $table->index(['college_id', 'fee_category_id'], 'fee_structure_items_category_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fee_structure_items', function (Blueprint $table) {
            $table->dropIndex('fee_structure_items_category_idx');
            $table->dropConstrainedForeignId('fee_category_id');
        });
    }
};
