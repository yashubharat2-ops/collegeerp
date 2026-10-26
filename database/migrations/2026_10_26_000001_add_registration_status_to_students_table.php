<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registration workflow state, kept separate from Student::STATUSES.
     *
     * NULL means a legacy/pre-existing student and is treated as approved.
     * Every column is nullable and unbackfilled: existing rows keep working
     * unchanged, and the down migration removes only what this migration adds.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->string('registration_status', 30)->nullable()->after('status');
            $table->timestamp('registration_submitted_at')->nullable()->after('registration_status');

            $table->index(['college_id', 'registration_status'], 'students_college_registration_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropIndex('students_college_registration_status_idx');
            $table->dropColumn(['registration_status', 'registration_submitted_at']);
        });
    }
};
