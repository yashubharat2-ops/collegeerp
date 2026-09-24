<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Communication Management Phase 1 — Notices / Announcements.
 *
 * Additive only: creates a new tenant-scoped table and touches no existing
 * table. Audience targets reference EXISTING Platform masters through
 * (target_type, target_id) — departments, programs and sections are never
 * duplicated. Because target_id points at different tables depending on
 * target_type, it cannot carry a single foreign key; the service layer
 * validates it against the active college.
 *
 * - status / priority / notice_type / target_type are strings (extensible,
 *   validated in the application), not database enums;
 * - slug is unique per college, including archived (soft-deleted) rows;
 * - attachments live on the private disk; only the generated key and display
 *   metadata are stored here;
 * - tenant-aware composite indexes back the listing filters and the
 *   dashboard counters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();

            $table->string('title');
            $table->string('slug');
            $table->string('notice_type', 50);
            $table->mediumText('content');

            $table->dateTime('publish_at');
            $table->dateTime('expires_at')->nullable();

            $table->string('status', 20)->default('draft');
            $table->string('priority', 20)->default('normal');

            $table->string('target_type', 30)->default('all');
            $table->unsignedBigInteger('target_id')->nullable();

            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 150)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['college_id', 'slug'], 'notices_college_slug_unique');
            $table->index(['college_id', 'status', 'publish_at'], 'notices_college_status_publish_idx');
            $table->index(['college_id', 'publish_at'], 'notices_college_publish_idx');
            $table->index(['college_id', 'priority'], 'notices_college_priority_idx');
            $table->index(['college_id', 'notice_type'], 'notices_college_type_idx');
            $table->index(['college_id', 'target_type', 'target_id'], 'notices_college_target_idx');
        });

        $driver = DB::connection()->getDriverName();

        // SQLite cannot add constraints to an existing table; the Form Request
        // and NoticeService enforce the same rule there.
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE `notices` ADD CONSTRAINT `notices_dates_check` CHECK (`expires_at` IS NULL OR `expires_at` > `publish_at`)');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "notices" ADD CONSTRAINT "notices_dates_check" CHECK ("expires_at" IS NULL OR "expires_at" > "publish_at")');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};
