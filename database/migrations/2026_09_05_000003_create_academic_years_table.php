<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('inactive')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['college_id', 'code']);
            $table->index(['college_id', 'status']);
            $table->check('ends_on > starts_on', 'academic_years_valid_dates');
        });
    }

    public function down(): void { Schema::dropIfExists('academic_years'); }
};
