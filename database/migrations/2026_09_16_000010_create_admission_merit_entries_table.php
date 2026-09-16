<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('admission_merit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merit_list_id')->constrained('admission_merit_lists')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('admission_applications')->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('admission_applicants')->cascadeOnDelete();
            // Flexible scoring: decimal for future different formulas, no hard-coded formula.
            $table->decimal('merit_score', 10, 2)->nullable()->index();
            $table->unsignedInteger('rank')->nullable()->index();
            $table->string('selection_status', 20)->default('pending')->index(); // selected, waitlisted, rejected, pending
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merit_list_id', 'application_id'], 'merit_list_application_unique');
            $table->index(['college_id', 'merit_list_id']);
            $table->index(['college_id', 'application_id']);
            $table->index(['college_id', 'applicant_id']);
            $table->index(['college_id', 'selection_status']);
            $table->index(['college_id', 'merit_list_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_merit_entries');
    }
};
