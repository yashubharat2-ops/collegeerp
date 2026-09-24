<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hostel Management (Phase 1) — the hostel master.
 *
 * A hostel is the top of the hierarchy
 * (College → Hostel → Building / Block → Room → Bed). It is tenant-scoped:
 * every row belongs to exactly one college and `college_id` is stamped from
 * the authenticated tenant context — never from request data.
 *
 * `code` is the hostel's own identifier, stored upper-cased and unique within
 * the college. It stays reserved on archived (soft-deleted) records so
 * historical references can never collide with a reused code.
 *
 * `(id, college_id)` is unique so children can carry a composite foreign key
 * that guarantees same-tenant ownership at the database level.
 *
 * Additive only: no existing table is modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hostels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->string('name', 255);
            $table->string('code', 50);
            $table->string('hostel_type', 100);
            $table->string('gender', 50);
            $table->text('address')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            // Identifiers remain reserved on archived records, preserving history.
            $table->unique(['college_id', 'code']);
            // Composite tenant anchor for child foreign keys.
            $table->unique(['id', 'college_id']);
            $table->index(['college_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hostels');
    }
};
