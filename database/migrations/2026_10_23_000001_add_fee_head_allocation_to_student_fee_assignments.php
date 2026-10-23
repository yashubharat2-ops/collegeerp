<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-Fee-Head allocation is an extension of StudentFeeAssignment, not a new
 * financial register. A NULL item id is the existing whole-structure assignment;
 * a non-NULL id is one immutable head snapshot. Payments, concessions and
 * refunds therefore continue to reference the same assignment parent.
 *
 * The head name and amount are financial snapshots. FeeStructureItem has no
 * soft deletes and may be removed by a later structure edit, so this migration
 * deliberately does NOT add a foreign key that would either block that edit or
 * null out the historical meaning of an assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_fee_assignments', function (Blueprint $table): void {
            $table->unsignedBigInteger('fee_structure_item_id')->nullable()->after('fee_structure_id');
            $table->string('fee_head_name')->nullable()->after('assigned_amount');
            $table->uuid('allocation_batch_id')->nullable()->after('remarks');

            $table->index(
                ['college_id', 'fee_structure_item_id', 'status'],
                'student_fee_assignments_head_status_idx'
            );
            // Idempotency is database-backed on every driver. NULL batch ids on
            // legacy/single assignments do not collide with one another.
            $table->unique(
                ['college_id', 'allocation_batch_id', 'student_enrollment_id', 'fee_structure_item_id'],
                'student_fee_allocation_batch_unique'
            );
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            // The original index treated every head of one structure as the
            // same whole-structure assignment. Replace it with two explicit
            // partial indexes; cancelled/deleted history remains non-blocking.
            DB::statement('DROP INDEX IF EXISTS student_fee_assignments_active_unique');
            DB::statement("CREATE UNIQUE INDEX student_fee_assignments_whole_active_unique ON student_fee_assignments (college_id, student_enrollment_id, fee_structure_id) WHERE fee_structure_item_id IS NULL AND deleted_at IS NULL AND status IN ('active', 'completed')");
            DB::statement("CREATE UNIQUE INDEX student_fee_assignments_head_active_unique ON student_fee_assignments (college_id, student_enrollment_id, fee_structure_item_id) WHERE fee_structure_item_id IS NOT NULL AND deleted_at IS NULL AND status IN ('active', 'completed')");
        }
        // MySQL/MariaDB retain the established service-level enrollment/student
        // locks for active partial-uniqueness semantics.
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS student_fee_assignments_whole_active_unique');
            DB::statement('DROP INDEX IF EXISTS student_fee_assignments_head_active_unique');
        }

        Schema::table('student_fee_assignments', function (Blueprint $table): void {
            $table->dropUnique('student_fee_allocation_batch_unique');
            $table->dropIndex('student_fee_assignments_head_status_idx');
            $table->dropColumn(['fee_structure_item_id', 'fee_head_name', 'allocation_batch_id']);
        });

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX student_fee_assignments_active_unique ON student_fee_assignments (college_id, student_enrollment_id, fee_structure_id) WHERE deleted_at IS NULL AND status IN ('active', 'completed')");
        }
    }
};
