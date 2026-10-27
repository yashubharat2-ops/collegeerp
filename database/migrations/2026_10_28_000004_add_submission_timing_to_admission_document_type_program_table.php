<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course-wise document requirements — one missing attribute, not a new system.
 *
 * `admission_document_types` already models the per-college document master
 * (code, name, `is_required`, allowed extensions/MIMEs, max size) and the pivot
 * `admission_document_type_program` already models "which documents apply to
 * which programme, and whether each is required there". That IS the
 * course-wise requirement mechanism, so the wizard resolves requirements from it
 * and no configuration table is duplicated.
 *
 * The brief additionally needs "documents that may be submitted later" per
 * course. That cannot be expressed today, so this migration adds one nullable
 * boolean to the pivot (`can_submit_later`, default false). Existing rows keep
 * their meaning: required-now stays required-now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admission_document_type_program', function (Blueprint $table) {
            $table->boolean('can_submit_later')->default(false)->after('is_required');
        });
    }

    public function down(): void
    {
        Schema::table('admission_document_type_program', function (Blueprint $table) {
            $table->dropColumn('can_submit_later');
        });
    }
};
