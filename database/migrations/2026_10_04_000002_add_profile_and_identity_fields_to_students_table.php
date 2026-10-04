<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The profile fields the Student Create/Edit form needs and the existing
     * `students` table does not have.
     *
     * Why columns on `students` and not new tables:
     *
     * - The Create/Edit form captures ONE primary parent/guardian block per
     *   student. A normalised `student_guardians` table would need its own
     *   CRUD, policy, permissions and portal before it earns its keep, and it
     *   would turn a single save into a multi-write workflow. The foundation
     *   design names guardians as a future extension point; until that
     *   milestone exists, one nullable column per field keeps the single-record
     *   write path, one audit entry and no new authorization surface. Nothing
     *   here duplicates a master table: every field is an attribute of the
     *   person.
     * - Identity/academic/additional fields below are equally per-student
     *   attributes with no master data of their own (blood group, mother
     *   tongue, previous school …), so no lookup table is invented for them.
     *
     * Why each field is genuinely missing (mapped to the form sections):
     *
     * - Parent/Guardian: no guardian data existed on `students` at all.
     * - Identity & Government IDs: no Aadhaar/APAAR/other government ID
     *   existed. The Aadhaar value is stored ENCRYPTED (`aadhaar_number`,
     *   text because ciphertext is far longer than 12 characters); only the
     *   last four digits are kept in clear text (`aadhaar_last4`) for the
     *   masked display "XXXX XXXX 1234", and `aadhaar_hash` holds a
     *   deterministic HMAC of the digits so a duplicate can be detected
     *   without decrypting rows.
     * - Contact: emergency contact was not recorded (the address/e-mail/phone
     *   fields already existed and are reused).
     * - Academic/Admission: `admission_date` and the admission provenance link
     *   already existed; the previous-school block did not.
     * - Additional Information: blood group, nationality, mother tongue and
     *   remarks did not exist. (Photo upload reuses the existing `photo_path`
     *   column — no new column.)
     *
     * Safety:
     * - Every column is nullable with no default: existing rows keep working and
     *   nothing is rewritten or backfilled.
     * - Nothing existing is modified, renamed or dropped.
     * - Tenant isolation is unchanged: these are plain columns on the already
     *   tenant-scoped `students` table (college_id + CollegeScope).
     * - The Aadhaar index is deliberately NOT unique: the table is
     *   soft-deletable, so a hard unique would keep occupying its key after a
     *   soft delete and block a legitimate re-registration (the same reasoning
     *   as the student-number/enrollment indexes). Uniqueness among LIVE rows
     *   is enforced at the validation boundary, per college.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // --- Parent / Guardian -----------------------------------------
            $table->string('father_name')->nullable()->after('category');
            $table->string('mother_name')->nullable()->after('father_name');
            $table->string('guardian_name')->nullable()->after('mother_name');
            $table->string('guardian_relation', 50)->nullable()->after('guardian_name');
            $table->string('guardian_phone', 30)->nullable()->after('guardian_relation');
            $table->string('guardian_email')->nullable()->after('guardian_phone');
            $table->string('guardian_occupation', 150)->nullable()->after('guardian_email');
            $table->text('guardian_address')->nullable()->after('guardian_occupation');

            // --- Identity / government IDs ---------------------------------
            // aadhaar_number and govt_id_number hold ciphertext (Laravel
            // Crypt / APP_KEY), never the readable number.
            $table->text('aadhaar_number')->nullable()->after('guardian_address');
            $table->string('aadhaar_last4', 4)->nullable()->after('aadhaar_number');
            $table->string('aadhaar_hash', 64)->nullable()->after('aadhaar_last4');
            $table->string('apaar_id', 30)->nullable()->after('aadhaar_hash');
            $table->string('govt_id_type', 30)->nullable()->after('apaar_id');
            $table->text('govt_id_number')->nullable()->after('govt_id_type');

            // --- Contact ---------------------------------------------------
            $table->string('emergency_contact_name')->nullable()->after('country');
            $table->string('emergency_contact_phone', 30)->nullable()->after('emergency_contact_name');

            // --- Academic / admission --------------------------------------
            $table->string('previous_school_name')->nullable()->after('admission_date');
            $table->string('previous_school_board', 100)->nullable()->after('previous_school_name');
            $table->string('previous_qualification', 100)->nullable()->after('previous_school_board');
            $table->unsignedSmallInteger('previous_exam_year')->nullable()->after('previous_qualification');
            $table->decimal('previous_percentage', 5, 2)->nullable()->after('previous_exam_year');

            // --- Additional information ------------------------------------
            $table->string('blood_group', 10)->nullable()->after('previous_percentage');
            $table->string('nationality', 100)->nullable()->after('blood_group');
            $table->string('mother_tongue', 100)->nullable()->after('nationality');
            $table->text('remarks')->nullable()->after('mother_tongue');

            // Per-college Aadhaar duplicate detection (see class docblock).
            $table->index(['college_id', 'aadhaar_hash'], 'students_college_aadhaar_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex('students_college_aadhaar_hash_idx');

            $table->dropColumn([
                'father_name', 'mother_name', 'guardian_name', 'guardian_relation',
                'guardian_phone', 'guardian_email', 'guardian_occupation', 'guardian_address',
                'aadhaar_number', 'aadhaar_last4', 'aadhaar_hash', 'apaar_id',
                'govt_id_type', 'govt_id_number',
                'emergency_contact_name', 'emergency_contact_phone',
                'previous_school_name', 'previous_school_board', 'previous_qualification',
                'previous_exam_year', 'previous_percentage',
                'blood_group', 'nationality', 'mother_tongue', 'remarks',
            ]);
        });
    }
};
