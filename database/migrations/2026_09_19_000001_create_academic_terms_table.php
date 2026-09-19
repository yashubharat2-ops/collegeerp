<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('academic_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->string('type', 30)->default('semester');
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('status', 20)->default('active')->index();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['college_id', 'academic_year_id', 'code']);
            $table->index(['college_id', 'academic_year_id']);
            $table->index(['college_id', 'status']);
            $table->index(['college_id', 'type']);
            $table->index(['college_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_terms');
    }
};
