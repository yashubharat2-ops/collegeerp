<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('faculties', function (Blueprint $table) {
            // Faculty is the existing Platform Staff/Faculty record. HR extends
            // that record instead of introducing a second employee table.
            $table->foreignId('designation_id')
                ->nullable()
                ->after('designation')
                ->constrained('designations')
                ->nullOnDelete();
            $table->string('alternate_phone', 50)->nullable()->after('phone');
            $table->string('gender', 30)->nullable()->after('alternate_phone');
            $table->date('date_of_birth')->nullable()->after('gender');
            $table->string('address_line_1')->nullable()->after('joining_date');
            $table->string('address_line_2')->nullable()->after('address_line_1');
            $table->string('city', 100)->nullable()->after('address_line_2');
            $table->string('state', 100)->nullable()->after('city');
            $table->string('postal_code', 30)->nullable()->after('state');
            $table->string('country', 100)->nullable()->after('postal_code');
            $table->string('emergency_contact_name')->nullable()->after('country');
            $table->string('emergency_contact_phone', 50)->nullable()->after('emergency_contact_name');
            $table->date('employment_end_date')->nullable()->after('emergency_contact_phone');
            $table->text('notes')->nullable()->after('employment_end_date');

            $table->index(['college_id', 'designation_id']);
            $table->index(['college_id', 'date_of_birth']);
        });
    }

    public function down(): void
    {
        // Additive HR migrations are intentionally not reversed in production.
        // The down method remains available for a clean test rollback while no
        // existing platform table or record is ever deleted by the up migration.
        Schema::table('faculties', function (Blueprint $table) {
            $table->dropForeign(['designation_id']);
            $table->dropColumn([
                'designation_id',
                'alternate_phone',
                'gender',
                'date_of_birth',
                'address_line_1',
                'address_line_2',
                'city',
                'state',
                'postal_code',
                'country',
                'emergency_contact_name',
                'emergency_contact_phone',
                'employment_end_date',
                'notes',
            ]);
        });
    }
};
