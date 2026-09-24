<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication Management Phase 1 — internal (in-app) notifications.
 *
 * Named `communication_notifications` on purpose: the framework
 * `notifications` table (Laravel's database notification channel used by
 * User::notifications()) already exists with a different, tenant-less shape
 * and is left untouched. Additive only.
 *
 * - recipient_type + recipient_id reference an EXISTING user / student /
 *   staff (faculty) record of the same college; person data is never
 *   duplicated. The id points at different tables depending on the type, so
 *   the tenant-safe existence check lives in the application layer
 *   (NotificationRecipients);
 * - read_at is null while unread;
 * - created_by is nullable for system-generated notifications;
 * - no SMS / e-mail / WhatsApp / push delivery columns — later phases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();

            $table->string('recipient_type', 30);
            $table->unsignedBigInteger('recipient_id');

            $table->string('title');
            $table->text('message');
            $table->string('notification_type', 50)->default('general');
            $table->string('priority', 20)->default('normal');

            $table->timestamp('read_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['college_id', 'recipient_type', 'recipient_id', 'read_at'], 'comm_notifications_recipient_idx');
            $table->index(['college_id', 'read_at'], 'comm_notifications_read_idx');
            $table->index(['college_id', 'created_at'], 'comm_notifications_created_idx');
            $table->index(['college_id', 'notification_type'], 'comm_notifications_type_idx');
            $table->index(['college_id', 'priority'], 'comm_notifications_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_notifications');
    }
};
