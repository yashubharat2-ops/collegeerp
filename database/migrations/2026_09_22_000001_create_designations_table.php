<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('code', 50);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // A designation code is a college-owned master identifier. Keeping
            // it unique for the lifetime of the row also prevents an inactive
            // designation from colliding with an active one later.
            $table->unique(['college_id', 'code']);
            $table->index(['college_id', 'name']);
            $table->index(['college_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('designations');
    }
};
