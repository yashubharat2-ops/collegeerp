<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('admission_document_type_program', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained('admission_document_types')->cascadeOnDelete();
            $table->boolean('is_required')->default(false);
            $table->timestamps();

            $table->unique(['college_id', 'program_id', 'document_type_id'], 'adoc_type_program_unique');
            $table->index(['college_id', 'program_id']);
            $table->index(['college_id', 'document_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_document_type_program');
    }
};
