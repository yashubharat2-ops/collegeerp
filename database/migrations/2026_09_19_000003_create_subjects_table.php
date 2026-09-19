<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->string('short_name', 50)->nullable();
            $table->string('subject_type', 50)->nullable();
            $table->decimal('credits', 5, 2)->nullable();
            $table->decimal('max_marks', 6, 2)->nullable();
            $table->decimal('passing_marks', 6, 2)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['college_id', 'code']);
            $table->index(['college_id', 'department_id']);
            $table->index(['college_id', 'name']);
            $table->index(['college_id', 'subject_type']);
            $table->index(['college_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
