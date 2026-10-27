<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage for the five-step registration wizard.
 *
 * Two ideas, deliberately separated:
 *
 * 1. `student_registration_drafts` — the wizard's working copy. Until the final
 *    "Submit Registration for Approval" the applicant is NOT a student: no
 *    `students` row exists, so a half-filled form can never appear in the
 *    student list, the dues ledger or any report. The draft holds the validated
 *    step payload as JSON plus the step the user is on, and it is scoped to
 *    college + the user who created it.
 *
 * 2. `student_registration_draft_documents` — documents uploaded while the
 *    draft has no student yet. The file itself is already on the private disk
 *    (server-generated path, tenant directory); this row only records the
 *    metadata and the file it belongs to. On submission the staged rows are
 *    ADOPTED into the existing `student_documents` table (same file, same
 *    architecture), and the draft rows are removed.
 *
 * `geo_locations` is reference data shared by all colleges (a state is not a
 * per-college fact). It ships EMPTY: no country / state / district / block /
 * village rows are invented here. The wizard renders dependent selects when
 * data exists for a level and falls back to free-text inputs when it does not,
 * so the form is usable on day one and becomes a dependent selector as soon as
 * an administrator loads the official directory (see GeoLocationSeeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_registration_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // draft | submitted  (submitted keeps the row for the audit trail and
            // for the "already submitted" screen; it is never re-editable)
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedTinyInteger('current_step')->default(1);

            // Step payloads, keyed by step number: {1: {...}, 2: {...}, ...}
            $table->json('payload')->nullable();

            // Set on submission, so a resubmitted form can never create a second
            // student or a second approval request.
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['college_id', 'user_id', 'status']);
        });

        Schema::create('student_registration_draft_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('draft_id')->constrained('student_registration_drafts')->cascadeOnDelete();

            // Reuses the existing per-college document type master, exactly like
            // student_documents does. Nullable for an "Other document" upload.
            $table->foreignId('document_type_id')->nullable()->constrained('admission_document_types')->nullOnDelete();

            $table->string('title');
            $table->string('file_path', 500);
            $table->string('original_filename');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['college_id', 'draft_id']);
        });

        Schema::create('geo_locations', function (Blueprint $table) {
            $table->id();
            // Self-referencing hierarchy: country → state → district → block → village
            $table->foreignId('parent_id')->nullable()->constrained('geo_locations')->cascadeOnDelete();
            $table->string('level', 20)->index();
            $table->string('name', 150);
            $table->string('code', 30)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['level', 'parent_id']);
            $table->unique(['parent_id', 'level', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_locations');
        Schema::dropIfExists('student_registration_draft_documents');
        Schema::dropIfExists('student_registration_drafts');
    }
};
