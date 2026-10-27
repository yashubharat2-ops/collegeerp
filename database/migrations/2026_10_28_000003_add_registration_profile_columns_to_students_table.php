<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registration-wizard profile columns. All nullable, nothing existing changed.
 *
 * MEDIA — the four additional images the wizard captures beside the portrait
 * (`students.photo_path`, which already existed):
 *   signature_path, father_photo_path, mother_photo_path, guardian_photo_path.
 * Every one is written by StudentPhotoService on the private disk with a
 * server-generated name, exactly like the portrait. Storing a father/mother
 * photo on the student row (instead of a new parent table) keeps ONE parent
 * record per student, which is what the module already models
 * (`students.father_name` / `mother_name`); a separate photo table would have
 * to duplicate those identities.
 *
 * ADDRESS — `students` already has address_line_1/2, city, state, postal_code,
 * country. The wizard adds:
 *   * the remaining permanent-address levels (district, block/tehsil, gram
 *     panchayat/ward) that the address format requires;
 *   * the optional foreign keys into `geo_locations` for the dependent
 *     selectors (nullable — the text columns remain the source of truth when no
 *     directory data is loaded);
 *   * a correspondence / temporary address block plus the
 *     `correspondence_same_as_permanent` flag, so "Same as Permanent" does not
 *     have to duplicate the text into nine columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // --- Media -----------------------------------------------------
            $table->string('signature_path')->nullable()->after('photo_path');
            $table->string('father_photo_path')->nullable()->after('signature_path');
            $table->string('mother_photo_path')->nullable()->after('father_photo_path');
            $table->string('guardian_photo_path')->nullable()->after('mother_photo_path');

            // --- Parent / guardian contact --------------------------------
            // The module already models ONE father, ONE mother and ONE guardian
            // per student (`father_name`, `mother_name`, `guardian_*`). Step 2
            // needs each of them to carry a mobile, an email and an occupation,
            // which only the guardian had; these columns complete the trio
            // instead of introducing a parent table that would duplicate the
            // names already stored here.
            $table->string('father_phone', 30)->nullable()->after('father_name');
            $table->string('father_email', 255)->nullable()->after('father_phone');
            $table->string('father_occupation', 150)->nullable()->after('father_email');
            $table->string('mother_phone', 30)->nullable()->after('mother_name');
            $table->string('mother_email', 255)->nullable()->after('mother_phone');
            $table->string('mother_occupation', 150)->nullable()->after('mother_email');

            // --- Permanent address: extra levels + optional geo references --
            $table->string('district', 100)->nullable()->after('state');
            $table->string('block_tehsil', 100)->nullable()->after('district');
            $table->string('gram_panchayat_ward', 150)->nullable()->after('block_tehsil');

            // Plain indexed ids rather than constrained() columns: a foreign key
            // cannot be added to an existing table on every supported engine,
            // and the chain is already enforced server-side on every write (a
            // mismatched parent/child pair is refused, and ids are only ever
            // taken from the directory). Keep these nullable so existing
            // students and every other write path are unaffected.
            $table->unsignedBigInteger('country_id')->nullable()->after('country')->index();
            $table->unsignedBigInteger('state_id')->nullable()->after('country_id')->index();
            $table->unsignedBigInteger('district_id')->nullable()->after('state_id')->index();
            $table->unsignedBigInteger('block_id')->nullable()->after('district_id')->index();
            $table->unsignedBigInteger('village_id')->nullable()->after('block_id')->index();

            // --- Correspondence / temporary address -------------------------
            $table->boolean('correspondence_same_as_permanent')->default(true);
            $table->text('correspondence_address_line_1')->nullable();
            $table->text('correspondence_address_line_2')->nullable();
            $table->string('correspondence_city', 100)->nullable();
            $table->string('correspondence_district', 100)->nullable();
            $table->string('correspondence_state', 100)->nullable();
            $table->string('correspondence_postal_code', 20)->nullable();
            $table->string('correspondence_country', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn([
                'signature_path', 'father_photo_path', 'mother_photo_path', 'guardian_photo_path',
                'father_phone', 'father_email', 'father_occupation',
                'mother_phone', 'mother_email', 'mother_occupation',
                'district', 'block_tehsil', 'gram_panchayat_ward',
                'country_id', 'state_id', 'district_id', 'block_id', 'village_id',
                'correspondence_same_as_permanent', 'correspondence_address_line_1',
                'correspondence_address_line_2', 'correspondence_city', 'correspondence_district',
                'correspondence_state', 'correspondence_postal_code', 'correspondence_country',
            ]);
        });
    }
};
