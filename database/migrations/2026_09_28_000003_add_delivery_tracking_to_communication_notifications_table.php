<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Communication Management Phase 2 — delivery / read tracking.
 *
 * ADDITIVE: two nullable timestamps are appended to the EXISTING
 * `communication_notifications` table. No notification record is duplicated
 * and `read_at` (Phase 1 read / unread) is left exactly as it is, so the
 * existing read/unread behaviour keeps working unchanged.
 *
 * The tracking state is derived, never stored twice:
 *   read      → read_at is set
 *   delivered → delivered_at is set
 *   sent      → sent_at is set
 *
 * Existing rows are backfilled from the data already present: every stored
 * notification was sent when it was created, and a read notification was
 * necessarily delivered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_notifications', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable()->after('priority');
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
        });

        DB::table('communication_notifications')->whereNull('sent_at')->update([
            'sent_at' => DB::raw('created_at'),
        ]);

        DB::table('communication_notifications')
            ->whereNull('delivered_at')
            ->whereNotNull('read_at')
            ->update(['delivered_at' => DB::raw('read_at')]);

        Schema::table('communication_notifications', function (Blueprint $table) {
            $table->index(['college_id', 'delivered_at'], 'comm_notifications_delivered_idx');
            $table->index(['college_id', 'sent_at'], 'comm_notifications_sent_idx');
        });
    }

    public function down(): void
    {
        Schema::table('communication_notifications', function (Blueprint $table) {
            $table->dropIndex('comm_notifications_delivered_idx');
            $table->dropIndex('comm_notifications_sent_idx');
            $table->dropColumn(['sent_at', 'delivered_at']);
        });
    }
};
