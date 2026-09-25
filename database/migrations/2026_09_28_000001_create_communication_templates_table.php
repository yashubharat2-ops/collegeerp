<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication Management Phase 2 — reusable SMS / e-mail templates.
 *
 * Additive migration: creates one new tenant-scoped table and touches no
 * Phase 1 table.
 *
 * - `code` is unique per college (a college may reuse another college's code);
 * - `subject` is nullable because SMS templates have no subject line;
 * - templates are DEFINITIONS only — nothing here talks to an external SMS or
 *   e-mail provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();

            $table->string('name');
            $table->string('code', 50);
            $table->string('channel', 20);
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('status', 20)->default('active');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['college_id', 'code'], 'comm_templates_college_code_unique');
            $table->index(['college_id', 'channel'], 'comm_templates_channel_idx');
            $table->index(['college_id', 'status'], 'comm_templates_status_idx');
            $table->index(['college_id', 'name'], 'comm_templates_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_templates');
    }
};
