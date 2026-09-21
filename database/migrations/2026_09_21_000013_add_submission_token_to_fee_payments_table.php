<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance / Fees — double-submission guard for fee collections.
 *
 * The collection form submits a random token; a resubmitted form (double click,
 * refresh, back-and-post) carries the SAME token and is refused by the unique
 * index below instead of recording the money twice. A genuinely repeated payment
 * starts from a fresh form and therefore carries a fresh token, so legitimate
 * identical instalments are unaffected.
 *
 * The column is nullable so rows written before this migration (and API callers
 * that do not send a token) stay valid; uniqueness is per college.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_payments', function (Blueprint $table) {
            $table->string('submission_token', 64)->nullable()->after('reference_number');

            $table->unique(['college_id', 'submission_token'], 'fee_payments_college_submission_unique');
        });
    }

    public function down(): void
    {
        Schema::table('fee_payments', function (Blueprint $table) {
            $table->dropUnique('fee_payments_college_submission_unique');
            $table->dropColumn('submission_token');
        });
    }
};
