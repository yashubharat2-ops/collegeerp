<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            // Nullable campus: a department may sit at college level (no campus).
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();

            // Codes are unique per college: the same code may exist in another college.
            $table->unique(['college_id', 'code']);
            $table->index(['college_id', 'name']);
            $table->index(['college_id', 'status']);
            $table->index(['college_id', 'campus_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('departments'); }
};
