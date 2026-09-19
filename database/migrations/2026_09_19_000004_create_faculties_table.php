<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('faculties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->string('employee_code', 50);
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('designation', 100)->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('employment_type', 50)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->date('joining_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['college_id', 'employee_code']);
            $table->index(['college_id', 'department_id']);
            $table->index(['college_id', 'status']);
            $table->index(['college_id', 'first_name', 'last_name']);
            $table->index(['college_id', 'employment_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faculties');
    }
};
