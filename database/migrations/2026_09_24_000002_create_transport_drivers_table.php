<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->foreignId('faculty_id')->constrained('faculties')->restrictOnDelete();
            $table->string('license_number', 50);
            $table->string('license_type', 255)->nullable();
            $table->date('license_expiry')->nullable();
            $table->date('joining_date')->nullable();
            $table->string('status', 255);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            // Identifiers remain reserved on archived records, preserving history.
            $table->unique(['college_id', 'license_number']);
            $table->index(['college_id', 'status']);
        });

        if (in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement("CREATE UNIQUE INDEX transport_drivers_live_unique ON transport_drivers (college_id, faculty_id) WHERE deleted_at IS NULL AND status = 'active'");
        } else {
            Schema::table('transport_drivers', function (Blueprint $table) {
                $table->unsignedBigInteger('active_staff_id')->nullable()->storedAs("CASE WHEN deleted_at IS NULL AND status = 'active' THEN faculty_id ELSE NULL END");
                $table->unique(['college_id', 'active_staff_id'], 'transport_drivers_live_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_drivers');
    }
};
