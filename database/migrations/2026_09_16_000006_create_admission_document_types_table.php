<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('admission_document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(false);
            // Comma separated allowed extensions/mimes for flexibility, validated in FormRequest.
            // Example: pdf,jpg,jpeg,png
            $table->string('allowed_extensions', 255)->nullable();
            $table->string('allowed_mimes', 500)->nullable();
            $table->unsignedInteger('max_size_kb')->default(5120);
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['college_id', 'code']);
            $table->index(['college_id', 'status']);
            $table->index(['college_id', 'is_required']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_document_types');
    }
};
