<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('campuses', function (Blueprint $table) {
            if (! Schema::hasColumn('campuses', 'short_name')) {
                $table->string('short_name', 50)->nullable()->after('code');
            }
            if (! Schema::hasColumn('campuses', 'city')) {
                $table->string('city', 100)->nullable()->after('address');
            }
            if (! Schema::hasColumn('campuses', 'state')) {
                $table->string('state', 100)->nullable()->after('city');
            }
            if (! Schema::hasColumn('campuses', 'pincode')) {
                $table->string('pincode', 20)->nullable()->after('state');
            }
            if (! Schema::hasColumn('campuses', 'phone')) {
                $table->string('phone', 30)->nullable()->after('pincode');
            }
            if (! Schema::hasColumn('campuses', 'email')) {
                $table->string('email', 255)->nullable()->after('phone');
            }
            if (! Schema::hasColumn('campuses', 'description')) {
                $table->text('description')->nullable()->after('email');
            }
            if (! Schema::hasColumn('campuses', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('description')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('campuses', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('campuses', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn(['short_name', 'city', 'state', 'pincode', 'phone', 'email', 'description', 'created_by', 'updated_by']);
        });
    }
};
