<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 30);
            $table->text('description')->nullable();
            $table->string('builtin_key', 30)->nullable();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['college_id', 'code']);
            $table->unique(['college_id', 'builtin_key']);
        });
        Schema::create('certificate_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->foreignId('certificate_type_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('body');
            $table->timestamps();
        });
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->foreignId('certificate_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('certificate_template_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_transfer_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('requested');
            $table->text('purpose')->nullable();
            $table->string('number', 100)->nullable();
            $table->text('template_snapshot')->nullable();
            $table->json('data_snapshot')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->unsignedInteger('verification_count')->default(0);
            foreach (['requested_by', 'generated_by', 'issued_by', 'last_verified_by'] as $column) {
                $table->foreignId($column)->nullable()->constrained('users')->nullOnDelete();
            }
            $table->timestamps();
            $table->unique(['college_id', 'number']);
            $table->index(['college_id', 'certificate_type_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('certificate_templates');
        Schema::dropIfExists('certificate_types');
    }
};
