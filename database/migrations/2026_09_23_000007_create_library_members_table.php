<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Library Management (Phase 2) — library members.
 *
 * A member is a college's library relationship with an existing student
 * enrollment. Person identity stays on Student / StudentEnrollment; this table
 * stores only the membership (code, dates, status). There is no parallel
 * member-name or member-person master.
 *
 * `member_code` is unique among the college's active (not soft-deleted) rows.
 * At most one *active* membership may exist for a given enrollment; an inactive
 * membership does not block a later active one, and a soft-deleted row never
 * blocks reuse.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_enrollment_id')->constrained('student_enrollments')->cascadeOnDelete();
            $table->string('member_code', 50);
            $table->date('membership_date');
            $table->date('expiry_date')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['college_id', 'member_code'], 'library_members_college_code_idx');
            $table->index(['college_id', 'status'], 'library_members_college_status_idx');
            $table->index(['college_id', 'student_enrollment_id'], 'library_members_college_enrollment_idx');
            $table->index(['college_id', 'expiry_date'], 'library_members_college_expiry_idx');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX library_members_active_code_uniq ON library_members (college_id, member_code) WHERE deleted_at IS NULL');
            DB::statement("CREATE UNIQUE INDEX library_members_active_enrollment_uniq ON library_members (college_id, student_enrollment_id) WHERE deleted_at IS NULL AND status = 'active'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('library_members');
    }
};
