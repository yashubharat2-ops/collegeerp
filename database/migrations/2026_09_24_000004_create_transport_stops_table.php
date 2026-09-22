<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('route_id');
            $table->foreign(['route_id', 'college_id'])->references(['id', 'college_id'])->on('transport_routes')->restrictOnDelete();
            $table->string('name', 255);
            $table->string('code', 50);
            $table->unsignedInteger('sequence');
            $table->time('pickup_time')->nullable();
            $table->time('drop_time')->nullable();
            $table->string('landmark', 255)->nullable();
            $table->string('status', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            // Identifiers remain reserved on archived records, preserving history.
            $table->unique(['college_id', 'route_id', 'code']);
            $table->index(['college_id', 'status']);
        });

        if (in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement("CREATE UNIQUE INDEX transport_stops_live_unique ON transport_stops (college_id, route_id, sequence) WHERE deleted_at IS NULL");
        } else {
            Schema::table('transport_stops', function (Blueprint $table) {
                $table->unsignedBigInteger('live_sequence')->nullable()->storedAs("CASE WHEN deleted_at IS NULL THEN sequence ELSE NULL END");
                $table->unique(['college_id', 'route_id', 'live_sequence'], 'transport_stops_live_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_stops');
    }
};
