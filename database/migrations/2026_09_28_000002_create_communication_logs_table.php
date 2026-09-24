<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication Management Phase 2 — SMS / e-mail delivery logs.
 *
 * Additive migration. One immutable row per attempted message:
 *
 * - `communication_template_id` is nullable (ad-hoc messages) and is nulled
 *   when the template is removed — the log keeps its own subject/content
 *   snapshot, so history never changes;
 * - `recipient` stores the address the message was addressed to (phone or
 *   e-mail). The optional `recipient_type` + `recipient_id` REFERENCE an
 *   existing user / student / staff record of the same college (see
 *   NotificationRecipients) instead of duplicating person data;
 * - `status` is queued / sent / delivered / failed;
 * - no gateway columns beyond `provider_reference`: Phase 2 does not connect
 *   to any external provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();

            $table->string('channel', 20);
            $table->string('recipient');
            $table->string('recipient_type', 30)->nullable();
            $table->unsignedBigInteger('recipient_id')->nullable();

            $table->foreignId('communication_template_id')->nullable()
                ->constrained('communication_templates')->nullOnDelete();

            $table->string('subject')->nullable();
            $table->text('content');

            $table->string('status', 20)->default('queued');
            $table->string('provider_reference')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('failure_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['college_id', 'channel', 'status'], 'comm_logs_channel_status_idx');
            $table->index(['college_id', 'status'], 'comm_logs_status_idx');
            $table->index(['college_id', 'created_at'], 'comm_logs_created_idx');
            $table->index(['college_id', 'communication_template_id'], 'comm_logs_template_idx');
            $table->index(['college_id', 'recipient_type', 'recipient_id'], 'comm_logs_recipient_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_logs');
    }
};
