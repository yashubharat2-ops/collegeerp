<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('student_transfers', function (Blueprint $table) {
            $table->foreignId('certificate_template_id')->nullable()->after('tc_status')->constrained('certificate_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('student_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('certificate_template_id');
        });
    }
};
