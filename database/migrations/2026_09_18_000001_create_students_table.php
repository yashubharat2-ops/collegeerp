<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Tenant-aware students table.
     *
     * A Student is the officially enrolled person, distinct from
     * AdmissionApplicant (the person during the enquiry/application lifecycle).
     *
     * Person data is copied at conversion time (snapshot semantics) rather than
     * joined live to AdmissionApplicant, so a Student record remains historically
     * stable even if the applicant is later corrected or soft-deleted.
     *
     * admission_application_id is the optional provenance link back to the
     * application the student was converted from; it is nullable because a
     * Student may also be created directly (e.g. legacy data import, transfer).
     */
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admission_application_id')->nullable()->constrained('admission_applications')->nullOnDelete();
            $table->string('student_number', 50);
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('alternate_phone', 30)->nullable();
            $table->string('gender', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('admission_date')->nullable();
            $table->text('address_line_1')->nullable();
            $table->text('address_line_2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Student number is unique within a college.
            //
            // admission_application_id carries an index (not a unique): conversion
            // idempotency is enforced transactionally in ConvertApplicationToStudent
            // (application-row lock + live-student check), mirroring the
            // college-row lock used by the admission number generators. A hard
            // unique here would also occupy its key after a soft delete, blocking
            // legitimate re-creation once a student is removed.
            $table->unique(['college_id', 'student_number']);
            $table->index(['admission_application_id']);
            $table->index(['college_id', 'status']);
            $table->index(['college_id', 'email']);
            $table->index(['college_id', 'phone']);
            $table->index(['college_id', 'first_name', 'last_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
