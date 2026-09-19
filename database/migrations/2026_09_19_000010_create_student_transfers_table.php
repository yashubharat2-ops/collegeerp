<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Tenant-aware student transfers / Transfer Certificates (TC).
     *
     * A transfer request walks a student OUT of the institution without ever
     * destroying their record: the workflow only changes statuses
     * (student.status / enrollment.status → withdrawn) and records the issued
     * TC. The Student row, its enrollments, academic records and documents all
     * remain for historical and legal retention, and rows here are soft
     * deleted at worst.
     *
     * Two status columns keep the two lifecycles honest:
     * - `status`    — the REQUEST workflow: pending → approved | rejected | cancelled
     * - `tc_status` — the CERTIFICATE lifecycle: pending → issued | cancelled
     * A TC can only be issued from an approved request, and issuing mints the
     * TC number server-side (GenerateTcNumber), never from the browser.
     *
     * `tc_number` is unique per college. Because rows are soft-deletable the
     * uniqueness includes `deleted_at`, so a retired request never permanently
     * occupies its TC number (NULLs are distinct in unique indexes on SQLite,
     * MySQL and PostgreSQL alike).
     */
    public function up(): void
    {
        Schema::create('student_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('student_enrollments')->nullOnDelete();

            $table->date('transfer_date');
            $table->text('reason');
            $table->string('destination_institution')->nullable();

            $table->string('status', 20)->default('pending')->index();

            $table->string('tc_number', 50)->nullable();
            $table->date('tc_issue_date')->nullable();
            $table->string('tc_status', 20)->default('pending')->index();
            $table->string('tc_file_path', 500)->nullable();
            $table->string('tc_original_filename')->nullable();
            $table->unsignedBigInteger('tc_file_size')->default(0);

            $table->text('remarks')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['college_id', 'tc_number', 'deleted_at']);
            $table->index(['college_id', 'student_id']);
            $table->index(['college_id', 'status']);
            $table->index(['college_id', 'tc_status']);
            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_transfers');
    }
};
