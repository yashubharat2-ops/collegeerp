<?php

namespace App\Domain\Student\Services;

use App\Domain\Student\Actions\GenerateStudentNumber;
use App\Domain\Student\Actions\GenerateEnrollmentNumber;
use App\Domain\Student\Support\Aadhaar;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Service for tenant-safe student and enrollment management.
 *
 * Controllers stay thin: they authorize, then delegate to this service for
 * transactional persistence, server-side number generation (via the college-
 * row-locked generators), and audit recording. Nothing here trusts
 * college_id/student_number/enrollment_number from the client.
 *
 * The Student profile form also routes through here, which is why the payload
 * normalisation lives next to the persistence: the Aadhaar masked tail and
 * duplicate digest are derived server-side, the portrait is stored on the
 * private disk under a generated name, and the optional first enrollment is
 * created in the same transaction as the student.
 */
class StudentService
{
    /**
     * Columns snapshotted into the audit log.
     *
     * Sensitive identity values are deliberately absent: `aadhaar_number`,
     * `aadhaar_hash` and `govt_id_number` are never written to an audit entry
     * (only the masked `aadhaar_last4` is, which is what the UI shows). The
     * stored photo path is not audited either — the record's existence is the
     * fact worth logging, not the private file key.
     */
    public const AUDITED = [
        'id', 'student_number', 'admission_application_id',
        'first_name', 'middle_name', 'last_name', 'email', 'phone', 'alternate_phone',
        'gender', 'category', 'date_of_birth', 'admission_date',
        'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country',
        'status',
        // Parent / guardian
        'father_name', 'mother_name', 'guardian_name', 'guardian_relation',
        'guardian_phone', 'guardian_email', 'guardian_occupation', 'guardian_address',
        // Identity (masked tail only)
        'aadhaar_last4', 'apaar_id', 'govt_id_type',
        // Contact
        'emergency_contact_name', 'emergency_contact_phone',
        // Academic / admission snapshot
        'previous_school_name', 'previous_school_board', 'previous_qualification',
        'previous_exam_year', 'previous_percentage',
        // Additional information
        'blood_group', 'nationality', 'mother_tongue', 'remarks',
    ];

    public const AUDITED_ENROLLMENT = ['id', 'enrollment_number', 'student_id', 'academic_year_id', 'program_id', 'section_id', 'enrollment_date', 'status', 'remarks'];

    public function __construct(
        private readonly GenerateStudentNumber $studentNumbers,
        private readonly GenerateEnrollmentNumber $enrollmentNumbers,
        private readonly StudentPhotoService $photos,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Create a student under a college. The student number is always generated
     * server-side. When $audit is true a "student.created" audit entry is
     * written; conversion flows pass audit:false and record "student.converted"
     * instead, so the two creation paths remain distinguishable.
     *
     * The Create/Edit form additionally sends the profile sections and,
     * optionally, a FIRST enrollment (see profilePayload()). The enrollment is
     * created in the same transaction as the student through the existing
     * createEnrollment(), so a year/program/section combination that fails its
     * checks can never leave a half-registered student behind — and the
     * generated enrollment number still comes from the college-row-locked
     * generator.
     */
    public function createStudent(array $data, int $collegeId, ?string $admissionDate = null, ?string $academicYearCode = null, bool $audit = true): Student
    {
        $profile = $this->profilePayload($data);

        $attributes = $profile['attributes'];
        $attributes['college_id'] = $collegeId;
        $attributes['student_number'] = $this->studentNumbers->execute($collegeId, $academicYearCode);
        $attributes['admission_date'] = $admissionDate ?? ($attributes['admission_date'] ?? now()->toDateString());

        // Stored before the row so the portrait can simply be part of the
        // insert; deleted again when the transaction does not commit.
        $storedPhoto = null;
        if ($profile['photo'] !== null) {
            $storedPhoto = $this->photos->store($profile['photo'], $collegeId);
            $attributes['photo_path'] = $storedPhoto;
        }

        try {
            return DB::transaction(function () use ($attributes, $profile, $collegeId, $academicYearCode, $audit): Student {
                $student = Student::create($attributes);

                if ($profile['enrollment'] !== null) {
                    $this->createEnrollment(
                        $profile['enrollment'] + ['student_id' => $student->id],
                        $collegeId,
                        $academicYearCode,
                    );
                }

                if ($audit) {
                    $this->audit->record('student.created', $student, [], $student->only(self::AUDITED));
                }

                return $student;
            });
        } catch (Throwable $e) {
            // The row was rolled back, so no unreferenced portrait may survive.
            $this->photos->delete($storedPhoto);
            throw $e;
        }
    }

    /**
     * Update a student from the profile form.
     *
     * Identity semantics (the form never echoes a stored number, so a blank
     * field can never mean "delete"):
     *
     * - blank Aadhaar / government ID → the stored value is kept;
     * - a supplied number → it replaces the stored one, re-encrypted and with
     *   its masked tail + duplicate digest rebuilt server-side;
     * - remove_aadhaar / remove_govt_id / remove_photo → the stored value is
     *   cleared, and the superseded portrait file is removed only after the
     *   change is saved.
     */
    public function updateStudent(Student $student, array $data): Student
    {
        $profile = $this->profilePayload($data);
        $attributes = $profile['attributes'];

        if ($profile['remove_aadhaar']) {
            $attributes['aadhaar_number'] = null;
            $attributes['aadhaar_last4'] = null;
            $attributes['aadhaar_hash'] = null;
        }

        // A government ID is a TYPE + NUMBER pair, so it is cleared as a pair:
        // either by the explicit remove flag, or by submitting no type at all
        // (the form's "— None —"), which is how an operator says "this student
        // has no other government ID".
        $typeCleared = array_key_exists('govt_id_type', $attributes)
            && ($attributes['govt_id_type'] === null || $attributes['govt_id_type'] === '');

        if ($profile['remove_govt_id'] || $typeCleared) {
            $attributes['govt_id_type'] = null;
            $attributes['govt_id_number'] = null;
        }

        $previousPhoto = $student->photo_path;
        $storedPhoto = null;

        if ($profile['photo'] !== null) {
            $storedPhoto = $this->photos->store($profile['photo'], (int) $student->college_id);
            $attributes['photo_path'] = $storedPhoto;
        } elseif ($profile['remove_photo']) {
            $attributes['photo_path'] = null;
        }

        $old = $student->only(self::AUDITED);

        try {
            $student->update($attributes);
        } catch (Throwable $e) {
            // Nothing referenced the new file: remove it so a failed save does
            // not leave an orphan on the private disk.
            $this->photos->delete($storedPhoto);
            throw $e;
        }

        // The superseded portrait is removed only once the new path is saved
        // (or the photo was cleared) — never before.
        if (is_string($previousPhoto) && $previousPhoto !== '' && $previousPhoto !== $student->photo_path) {
            $this->photos->delete($previousPhoto);
        }

        $this->audit->record('student.updated', $student, $old, $student->only(self::AUDITED));

        return $student;
    }

    /**
     * Split and harden a validated Student-form payload.
     *
     * Keys the browser may send but that are NOT student columns — the photo
     * upload, the remove_* flags and the optional first-enrollment block — are
     * removed here, so they can never reach Student::create(). Identity values
     * are normalised and their derived columns rebuilt: `aadhaar_last4` and the
     * `aadhaar_hash` duplicate digest are never accepted from the browser, and
     * a blank Aadhaar or government-ID number is simply absent (an update then
     * keeps what is stored) instead of becoming an encryption of "".
     *
     * @return array{attributes: array<string, mixed>, enrollment: array<string, mixed>|null, photo: UploadedFile|null, remove_photo: bool, remove_aadhaar: bool, remove_govt_id: bool}
     */
    private function profilePayload(array $data): array
    {
        $enrollment = [];

        foreach (['academic_year_id', 'program_id', 'section_id', 'enrollment_date'] as $key) {
            if (! empty($data[$key])) {
                $enrollment[$key] = $data[$key];
            }

            unset($data[$key]);
        }

        $photo = $data['photo'] ?? null;
        $photo = $photo instanceof UploadedFile ? $photo : null;
        unset($data['photo']);

        $removePhoto = (bool) ($data['remove_photo'] ?? false);
        $removeAadhaar = (bool) ($data['remove_aadhaar'] ?? false);
        $removeGovtId = (bool) ($data['remove_govt_id'] ?? false);
        unset($data['remove_photo'], $data['remove_aadhaar'], $data['remove_govt_id']);

        $digits = Aadhaar::normalise($data['aadhaar_number'] ?? null);

        if ($digits !== null) {
            $data['aadhaar_number'] = $digits;              // the model's accessor encrypts it
            $data['aadhaar_last4'] = Aadhaar::last4($digits);
            $data['aadhaar_hash'] = Aadhaar::hash($digits);
        } else {
            unset($data['aadhaar_number']);
        }

        // Neither identity number is ever echoed back into the form, so a blank
        // field has to mean "keep the stored value" — not "overwrite it with
        // null". An update that is submitted with the field left empty (the
        // normal case) therefore leaves the encrypted column untouched; only a
        // supplied number, or the type/remove pair above, changes it.
        $govtNumber = $data['govt_id_number'] ?? null;
        if (! is_string($govtNumber) || trim($govtNumber) === '') {
            unset($data['govt_id_number']);
        }

        return [
            'attributes' => $data,
            'enrollment' => $enrollment === [] ? null : $enrollment,
            'photo' => $photo,
            'remove_photo' => $removePhoto,
            'remove_aadhaar' => $removeAadhaar,
            'remove_govt_id' => $removeGovtId,
        ];
    }

    public function deleteStudent(Student $student): void
    {
        $snapshot = $student->only(self::AUDITED);

        $student->delete();

        $this->audit->record('student.deleted', $student, $snapshot, []);
    }

    /**
     * Create an enrollment for a tenant-owned, tenant-scoped student.
     *
     * Tenant-aware validation with transactional duplicate-active protection:
     * the student row is locked for update, the academic year/program are
     * re-resolved through their CollegeScope (foreign-college ids resolve to
     * null), and the enrollment number is generated server-side.
     */
    public function createEnrollment(array $data, int $collegeId, ?string $academicYearCode = null, bool $audit = true): StudentEnrollment
    {
        return DB::transaction(function () use ($data, $collegeId, $academicYearCode, $audit): StudentEnrollment {
            $studentId = $data['student_id'];
            $yearId = $data['academic_year_id'];
            $programId = $data['program_id'] ?? null;

            // Lock the student row to serialize active-duplicate detection, and
            // verify same-college ownership.
            $student = Student::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereKey($studentId)
                ->lockForUpdate()
                ->first();

            if (! $student) {
                abort(404, 'Student not found in this college context.');
            }

            $year = \App\Models\AcademicYear::query()->find($yearId);
            if (! $year) {
                abort(404, 'Academic year not found in this college context.');
            }

            $program = $programId ? \App\Models\Program::query()->find($programId) : null;
            if ($programId && ! $program) {
                abort(404, 'Program not found in this college context.');
            }

            // Optional Section / Batch: resolved tenant-scoped (a foreign
            // college's section resolves to null) and additionally checked for
            // contextual validity — a Section belongs to exactly one academic
            // year + program pair, so it must match this enrollment's own.
            $sectionId = $data['section_id'] ?? null;
            if ($sectionId) {
                $section = \App\Models\Section::query()->find($sectionId);

                if (! $section) {
                    abort(404, 'Section not found in this college context.');
                }

                if ((int) $section->academic_year_id !== (int) $yearId) {
                    throw ValidationException::withMessages([
                        'section_id' => 'The selected section does not belong to the selected academic year.',
                    ]);
                }

                if ($programId && (int) $section->program_id !== (int) $programId) {
                    throw ValidationException::withMessages([
                        'section_id' => 'The selected section does not belong to the selected program.',
                    ]);
                }
            }

            // No duplicate ACTIVE enrollment for the same student/year/program.
            // Note: the section is an attribute of the enrollment and takes no
            // part in this key — being in two sections of the same year/program
            // is still one duplicate enrollment.
            $duplicate = StudentEnrollment::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->where('student_id', $studentId)
                ->where('academic_year_id', $yearId)
                ->where('program_id', $programId)
                ->whereNull('deleted_at')
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'student_id' => 'An active enrollment already exists for this student, academic year and program.',
                ]);
            }

            $enrollment = StudentEnrollment::create([
                'college_id' => $collegeId,
                'student_id' => $studentId,
                'academic_year_id' => $yearId,
                'program_id' => $programId,
                'section_id' => $sectionId,
                'enrollment_number' => $this->enrollmentNumbers->execute($collegeId, $academicYearCode ?? $year->code),
                'enrollment_date' => $data['enrollment_date'] ?? now()->toDateString(),
                'status' => $data['status'] ?? 'active',
                'remarks' => $data['remarks'] ?? null,
            ]);

            if ($audit) {
                $this->audit->record('student_enrollment.created', $enrollment, [], $enrollment->only(self::AUDITED_ENROLLMENT));
            }

            return $enrollment;
        });
    }

    /**
     * Update an enrollment. FKs stay tenant-scoped and can never be re-pointed
     * at another college's academic year/program.
     */
    public function updateEnrollment(StudentEnrollment $enrollment, array $data, int $collegeId): StudentEnrollment
    {
        return DB::transaction(function () use ($enrollment, $data, $collegeId): StudentEnrollment {
            // Serialize with createEnrollment(): lock the OWNING student row first
            // (the same lock createEnrollment takes), then re-read the enrollment
            // under that lock. The caller's model may be stale (another request
            // may have reactivated or cancelled it since it was loaded), so every
            // check below runs against the committed state, never the passed copy.
            $student = Student::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereKey($enrollment->student_id)
                ->lockForUpdate()
                ->first();

            if (! $student) {
                abort(404, 'Student not found in this college context.');
            }

            $enrollment = StudentEnrollment::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->where('student_id', $student->id)
                ->whereNull('deleted_at')
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->first();

            if (! $enrollment) {
                abort(404, 'Enrollment not found in this college context.');
            }

            $year = \App\Models\AcademicYear::query()->find($enrollment->academic_year_id);
            if (! $year) {
                abort(404, 'Academic year not found in this college context.');
            }

            if ($enrollment->program_id) {
                $program = \App\Models\Program::query()->find($enrollment->program_id);
                if (! $program) {
                    abort(404, 'Program not found in this college context.');
                }
            }

            // A section change within the same academic year/program is a
            // legitimate, audited correction. The section is re-resolved
            // tenant-scoped and must match the enrollment's own (immutable)
            // year/program, so an enrollment can never be re-pointed at
            // another college's or another year's section.
            if (array_key_exists('section_id', $data)) {
                $sectionId = $data['section_id'];

                if ($sectionId) {
                    $section = \App\Models\Section::query()->find($sectionId);

                    if (! $section) {
                        abort(404, 'Section not found in this college context.');
                    }

                    if ((int) $section->academic_year_id !== (int) $enrollment->academic_year_id) {
                        throw ValidationException::withMessages([
                            'section_id' => 'The selected section does not belong to this enrollment\'s academic year.',
                        ]);
                    }

                    if ($enrollment->program_id && (int) $section->program_id !== (int) $enrollment->program_id) {
                        throw ValidationException::withMessages([
                            'section_id' => 'The selected section does not belong to this enrollment\'s program.',
                        ]);
                    }
                }
            }

            // Reject a status reactivation that would collide with another live
            // enrollment of the same student/year/program.
            $newStatus = $data['status'] ?? $enrollment->status;
            if ($newStatus === 'active' && $enrollment->status !== 'active') {
                $duplicate = StudentEnrollment::withoutGlobalScopes()
                    ->where('college_id', $collegeId)
                    ->where('student_id', $enrollment->student_id)
                    ->where('academic_year_id', $enrollment->academic_year_id)
                    ->where('program_id', $enrollment->program_id)
                    ->whereNull('deleted_at')
                    ->whereKeyNot($enrollment->id)
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'status' => 'Another active enrollment already exists for this student, academic year and program.',
                    ]);
                }
            }

            $old = $enrollment->only(self::AUDITED_ENROLLMENT);
            $enrollment->update($data);
            $this->audit->record('student_enrollment.updated', $enrollment, $old, $enrollment->only(self::AUDITED_ENROLLMENT));

            return $enrollment;
        });
    }

    public function deleteEnrollment(StudentEnrollment $enrollment): void
    {
        $snapshot = $enrollment->only(self::AUDITED_ENROLLMENT);

        $enrollment->delete();

        $this->audit->record('student_enrollment.deleted', $enrollment, $snapshot, []);
    }
}
