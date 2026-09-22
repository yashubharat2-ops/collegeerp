<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_id')->constrained('payrolls')->cascadeOnDelete();
            $table->foreignId('salary_component_id')->nullable()->constrained('salary_components')->nullOnDelete();
            $table->string('component_name', 255);
            $table->string('component_code', 50);
            $table->string('component_type', 20);
            $table->string('calculation_type', 20);
            $table->decimal('input_value', 14, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['college_id', 'payroll_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
    }
};
