<?php

namespace App\Models;

use App\Domain\Foundation\Traits\BelongsToCollege;
use App\Domain\Student\Support\Aadhaar;
use App\Domain\Student\Support\SensitiveIdentity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Student — the officially enrolled person, distinct from AdmissionApplicant.
 *
 * AdmissionApplicant is the single source of truth for the person during the
 * enquiry/application lifecycle; Student is the lifecycle entity that begins
 * at enrollment. Person data is snapshotted onto the Student at conversion time
 * (rather than joined live), so the record remains historically stable even if
 * the applicant is later corrected or soft-deleted. Provenance is preserved via
 * admission_application_id.
 *
 * A Student holds no permanent academic-year/program binding: the recurring,
 * tenant-scoped enrollment history lives in StudentEnrollment.
 *
 * Tenant isolation via BelongsToCollege + CollegeScope.
 */
class Student extends Model
{
    use HasFactory, SoftDeletes, BelongsToCollege;

    public const STATUSES = ['active', 'inactive', 'graduated', 'suspended', 'withdrawn'];

    /**
     * Gender values the UI offers and the write side accepts.
     *
     * A documented convention, not a schema constraint (the column is a plain
     * nullable string), so an institution that needs another value changes this
     * list rather than the database.
     */
    public const GENDERS = ['male', 'female', 'other', 'prefer_not_to_say'];

    /**
     * Admission (reservation) categories offered by the Student list filter and
     * the student form.
     *
     * Same convention rule as GENDERS: `students.category` is a nullable string
     * column added by 2026_10_04_000001_add_category_to_students_table, and this
     * list is the validation/UI vocabulary — never a duplicated master table.
     */
    public const CATEGORIES = ['general', 'obc', 'sc', 'st', 'ews', 'other'];

    /**
     * Guardian relationship vocabulary for the Parent/Guardian block.
     *
     * Same convention rule as GENDERS/CATEGORIES: `students.guardian_relation`
     * is a nullable string and this list is the validation/UI vocabulary —
     * never a schema enum, so an institution that needs another relationship
     * changes this list rather than the database.
     */
    public const GUARDIAN_RELATIONS = ['father', 'mother', 'guardian', 'other'];

    /**
     * Government ID types accepted alongside Aadhaar.
     *
     * Aadhaar has its own dedicated (encrypted + masked) column; this list
     * covers the other identity documents a college may record — and never
     * includes an "aadhaar" entry, so the number cannot be duplicated into a
     * second, differently-treated column.
     */
    public const GOVT_ID_TYPES = ['pan', 'passport', 'driving_licence', 'voter_id', 'ration_card', 'other'];

    /** Blood groups offered by the form; `unknown` is stored explicitly. */
    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'unknown'];

    /**
     * Human label for a stored category value ("obc" → "OBC").
     */
    public static function categoryLabel(?string $category): string
    {
        return $category === null || $category === ''
            ? '—'
            : match ($category) {
                'obc' => 'OBC',
                'sc' => 'SC',
                'st' => 'ST',
                'ews' => 'EWS',
                default => ucfirst(str_replace('_', ' ', $category)),
            };
    }

    /**
     * Human label for a stored guardian relationship ("father" → "Father",
     * "other" → "Other / legal guardian").
     */
    public static function guardianRelationLabel(?string $relation): string
    {
        return $relation === null || $relation === ''
            ? '—'
            : match ($relation) {
                'father' => 'Father',
                'mother' => 'Mother',
                'guardian' => 'Guardian',
                default => 'Other / legal guardian',
            };
    }

    /**
     * Human label for a stored government ID type ("driving_licence" →
     * "Driving licence").
     */
    public static function govtIdTypeLabel(?string $type): string
    {
        return $type === null || $type === ''
            ? '—'
            : match ($type) {
                'pan' => 'PAN',
                'passport' => 'Passport',
                'driving_licence' => 'Driving licence',
                'voter_id' => 'Voter ID',
                'ration_card' => 'Ration card',
                default => 'Other government ID',
            };
    }

    public function certificates(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Certificate::class, 'student_id');
    }

    protected $fillable = [
        'college_id',
        'admission_application_id',
        'student_number',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'phone',
        'alternate_phone',
        'gender',
        'category',
        'date_of_birth',
        'admission_date',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'photo_path',
        'status',
        'created_by',
        'updated_by',
        // Parent / Guardian
        'father_name',
        'mother_name',
        'guardian_name',
        'guardian_relation',
        'guardian_phone',
        'guardian_email',
        'guardian_occupation',
        'guardian_address',
        // Identity & government IDs (aadhaar_* and govt_id_number are encrypted)
        'aadhaar_number',
        'aadhaar_last4',
        'aadhaar_hash',
        'apaar_id',
        'govt_id_type',
        'govt_id_number',
        // Contact
        'emergency_contact_name',
        'emergency_contact_phone',
        // Academic / admission
        'previous_school_name',
        'previous_school_board',
        'previous_qualification',
        'previous_exam_year',
        'previous_percentage',
        // Additional information
        'blood_group',
        'nationality',
        'mother_tongue',
        'remarks',
    ];

    /**
     * Sensitive identity values are hidden from array/JSON serialisation, so no
     * `toArray()`/`toJson()` (API resource, log line, accidental `dd()`) can
     * ever expose the Aadhaar ciphertext or the other government ID number.
     * Views read them only through the masking helpers below.
     */
    protected $hidden = [
        'aadhaar_number',
        'aadhaar_hash',
        'govt_id_number',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'admission_date' => 'date',
            'previous_exam_year' => 'integer',
            'previous_percentage' => 'decimal:2',
        ];
    }

    /**
     * Aadhaar number: encrypted at rest, decrypted on read.
     *
     * Deliberately an Attribute accessor rather than the `encrypted` cast: the
     * setter has to map null/blank to a real NULL column (never to an
     * encryption of ""), so "not recorded" stays unambiguous and the duplicate
     * check can test `aadhaar_hash` directly.
     */
    protected function aadhaarNumber(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => Aadhaar::decrypt($value),
            // `mixed` on purpose: a hand-crafted request can deliver an array,
            // and normalise() already treats anything non-scalar as absent.
            set: fn (mixed $value) => ($digits = Aadhaar::normalise($value)) === null
                ? null
                : Aadhaar::encrypt($digits),
        );
    }

    /** Other government ID number (PAN, passport, …): encrypted at rest. */
    protected function govtIdNumber(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => SensitiveIdentity::decrypt($value),
            set: fn (mixed $value) => SensitiveIdentity::encrypt(is_string($value) ? $value : null),
        );
    }

    /**
     * Masked Aadhaar for display: "XXXX XXXX 1234", never the full number.
     * Null when no Aadhaar is recorded.
     */
    public function maskedAadhaar(): ?string
    {
        return Aadhaar::mask($this->aadhaar_last4);
    }

    /**
     * Masked other-government-ID for display ("XXXXX1234"). Null when nothing
     * is recorded or the stored ciphertext cannot be decrypted.
     */
    public function maskedGovtIdNumber(): ?string
    {
        return SensitiveIdentity::maskTail($this->govt_id_number);
    }

    public function hasAadhaar(): bool
    {
        return $this->aadhaar_last4 !== null && $this->aadhaar_last4 !== '';
    }

    /**
     * Who to contact first for this student: the named guardian, else the
     * father, else the mother. Null when the profile records none of them.
     */
    public function primaryGuardianName(): ?string
    {
        foreach ([$this->guardian_name, $this->father_name, $this->mother_name] as $name) {
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return null;
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /**
     * Provenance link back to the approved admission application, when the
     * student was created via admission-to-student conversion.
     */
    public function admissionApplication(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'admission_application_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class, 'student_id');
    }

    public function academicRecords(): HasMany
    {
        return $this->hasMany(StudentAcademicRecord::class, 'student_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class, 'student_id');
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(StudentPromotion::class, 'student_id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(StudentTransfer::class, 'student_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Full display name from the snapshot person data.
     */
    public function fullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ]))) ?: $this->student_number;
    }

    /**
     * The student's current (active) enrollment, if any.
     *
     * Deterministic: the oldest-created active enrollment wins, with the id as
     * a tiebreak, so the "current enrollment" never flips between requests.
     */
    public function currentEnrollment(): ?StudentEnrollment
    {
        return $this->enrollments
            ->filter(fn (StudentEnrollment $enrollment) => $enrollment->status === 'active')
            ->sortBy(fn (StudentEnrollment $enrollment) => sprintf(
                '%011d%011d',
                $enrollment->created_at?->timestamp ?? 0,
                $enrollment->id
            ))
            ->first();
    }
}
