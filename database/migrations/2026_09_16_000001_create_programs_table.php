<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            // Nullable department: a program may sit at college level (no department).
            // When set, the department must belong to the same college. As with
            // departments.campus_id, this tenant-match invariant is enforced by the
            // tenant-scoped model layer; the (college_id, department_id) index below
            // backs those scoped checks. A composite foreign key would additionally
            // require a new unique key on departments and is deferred until the
            // production database engine is fixed (see docs/architecture.md).
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->string('short_name', 50)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();

            // Codes are unique per college: the same code may exist in another college.
            $table->unique(['college_id', 'code']);
            $table->index(['college_id', 'name']);
            $table->index(['college_id', 'status']);
            $table->index(['college_id', 'department_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('programs'); }
};
