<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transport Phase 2 — Transport Fee Structure (transport fee categories).
 *
 * The transport-side pricing master: how much a route/stop combination costs
 * for an academic year and for which period it applies. Amounts are entered
 * per college — nothing is hard-coded.
 *
 * The route/stop references are OPTIONAL (a structure may price a whole route
 * or a single stop) and are composite foreign keys, so a foreign college's
 * route or stop can never be referenced even by a crafted request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_fee_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();

            $table->unsignedBigInteger('transport_route_id')->nullable();
            $table->foreign(['transport_route_id', 'college_id'])
                ->references(['id', 'college_id'])->on('transport_routes')->nullOnDelete();

            $table->unsignedBigInteger('transport_stop_id')->nullable();
            $table->foreign(['transport_stop_id', 'college_id'])
                ->references(['id', 'college_id'])->on('transport_stops')->nullOnDelete();

            $table->string('name', 255);
            $table->string('code', 50);
            $table->decimal('amount', 12, 2);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Identifiers remain reserved on archived records, preserving history.
            $table->unique(['college_id', 'code']);
            $table->index(['college_id', 'academic_year_id', 'status'], 'transport_fee_structures_year_status_idx');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `transport_fee_structures` ADD CONSTRAINT `transport_fee_structures_amount_positive` CHECK (`amount` > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_fee_structures');
    }
};
