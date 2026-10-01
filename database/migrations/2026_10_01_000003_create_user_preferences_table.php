<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user interface preferences for the signed-in account panel.
 *
 * Deliberately small and deliberately NOT a second settings system: the college-wide
 * `institutional_settings` table stays the only place administrator-owned
 * configuration lives, and this table stores nothing but a handful of interface
 * switches that the authenticated user owns for themselves. Values are UI chrome
 * only (which sidebar state to open in, whether the header names the user) — never
 * a record, tenant mapping, permission or secret.
 *
 * The row is keyed by the user alone, so switching the active college can never read
 * or write another tenant's data: a preference is attached to the person, not to the
 * college, and no college identifier is stored at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 150);
            $table->text('value')->nullable();
            $table->string('type', 30)->default('boolean');
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
