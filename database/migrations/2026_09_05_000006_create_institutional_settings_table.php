<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('institutional_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->string('key', 150);
            $table->longText('value')->nullable();
            $table->string('type', 30)->default('string');
            $table->boolean('is_public')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['college_id', 'key']);
            $table->index(['college_id', 'is_public']);
        });
    }

    public function down(): void { Schema::dropIfExists('institutional_settings'); }
};
