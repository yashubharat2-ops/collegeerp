<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('inactive')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['college_id', 'code']);
            $table->index(['college_id', 'status']);
        });

        // Laravel 12 compatible database-level CHECK constraint
        // Rule: starts_on < ends_on (i.e. ends_on > starts_on)
        // Supported drivers: MySQL, MariaDB, PostgreSQL, SQL Server
        // SQLite is intentionally skipped for in-memory testing compatibility; model-level invariant covers it.
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE `academic_years` ADD CONSTRAINT `academic_years_valid_dates` CHECK (`ends_on` > `starts_on`)');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "academic_years" ADD CONSTRAINT "academic_years_valid_dates" CHECK ("ends_on" > "starts_on")');
        } elseif ($driver === 'sqlsrv') {
            DB::statement('ALTER TABLE [academic_years] ADD CONSTRAINT [academic_years_valid_dates] CHECK ([ends_on] > [starts_on])');
        }
        // sqlite: skip DB-level CHECK for testing compatibility
    }

    public function down(): void { Schema::dropIfExists('academic_years'); }
};
