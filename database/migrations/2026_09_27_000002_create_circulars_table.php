<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Communication Management Phase 1 — Circulars.
 *
 * A separate module from Notices (own table, numbering and workflow), not an
 * alias. Additive only: no existing table is touched.
 *
 * - circular_number is unique within a college, including archived
 *   (soft-deleted) circulars, so an issued number is never reused;
 * - issue_date is the formal date of the circular; publish_at is optional
 *   (stamped on publication when not scheduled);
 * - target_type is an extensible audience label (all / students / staff);
 * - attachments live on the private disk; only the generated key and display
 *   metadata are stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('circulars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();

            $table->string('circular_number', 50);
            $table->string('title');
            $table->string('subject');
            $table->mediumText('content');

            $table->date('issue_date');
            $table->dateTime('publish_at')->nullable();
            $table->dateTime('expires_at')->nullable();

            $table->string('status', 20)->default('draft');
            $table->string('target_type', 30)->default('all');

            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 150)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['college_id', 'circular_number'], 'circulars_college_number_unique');
            $table->index(['college_id', 'status', 'issue_date'], 'circulars_college_status_issue_idx');
            $table->index(['college_id', 'issue_date'], 'circulars_college_issue_idx');
            $table->index(['college_id', 'target_type'], 'circulars_college_target_idx');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE `circulars` ADD CONSTRAINT `circulars_dates_check` CHECK (`expires_at` IS NULL OR `publish_at` IS NULL OR `expires_at` > `publish_at`)');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "circulars" ADD CONSTRAINT "circulars_dates_check" CHECK ("expires_at" IS NULL OR "publish_at" IS NULL OR "expires_at" > "publish_at")');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('circulars');
    }
};
