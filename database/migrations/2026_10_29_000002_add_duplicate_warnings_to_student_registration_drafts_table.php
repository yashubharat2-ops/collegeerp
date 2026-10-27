<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the "possible duplicate" hints found while a draft is being filled in.
 *
 * These are WARNINGS, never blocks: they are raised when a name matches another
 * student AND a corroborating detail (date of birth, father's or mother's name)
 * also agrees. Verified identifiers (phone, Aadhaar, APAAR) are handled by
 * RegistrationIdentityGuard and do block — see RegistrationDuplicateDetector.
 *
 * Keeping them on the draft means the final review can show exactly what the
 * approver will be told, and the submit path can copy them into the approval
 * request instead of recomputing (and silently changing) them mid-flight.
 *
 * Additive and nullable: existing drafts simply have no warnings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_registration_drafts', function (Blueprint $table) {
            $table->json('duplicate_warnings')->nullable()->after('payload');
        });
    }

    public function down(): void
    {
        Schema::table('student_registration_drafts', function (Blueprint $table) {
            $table->dropColumn('duplicate_warnings');
        });
    }
};
