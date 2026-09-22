<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_structure_id')->constrained('salary_structures')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('code', 50);
            $table->string('component_type', 20); // earning or deduction
            $table->string('calculation_type', 20)->default('fixed'); // fixed or percentage
            $table->decimal('value', 12, 2)->default(0);
            $table->string('basis', 50)->nullable(); // basic, gross, earnings or component code
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['college_id', 'salary_structure_id', 'code'], 'salary_components_structure_code_unique');
            $table->index(['college_id', 'salary_structure_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_components');
    }
};
