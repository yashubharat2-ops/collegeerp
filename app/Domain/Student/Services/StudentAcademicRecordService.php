<?php

namespace App\Domain\Student\Services;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentAcademicRecord;
use App\Models\StudentEnrollment;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tenant-safe academic-record management for the Students module.
 *
 * Academic records describe progression; they never re-create academic master
 * data. Every reference (academic year, academic term, program, section,
 * enrollment) is re-resolved here — server-side and authoritative — through
 * its CollegeScope, so a foreign college's id resolves to a 404 and a
 * contextually wrong id (a section from another year/program, a term from
 * another year, an enrollment of another student) is rejected even if it
 * somehow bypassed the Form Request.
 *
 * One live record per student + academic year + academic term, enforced
 * transactionally under a student-row lock, mirroring the duplicate-active
 * enrollment protection in StudentService.
 */
class StudentAcademicRecordService
{
    public const AUDITED = [
        'id', 'student_id', 'enrollment_id', 'academic_year_id', 'academic_term_id',
        'program_id', 'section_id', 'academic_status', 'promotion_status',
        'completion_status', 'remarks',
    ];

    public function __construct(private readonly AuditLogService $audit) {}

    public function create(array $data, int $collegeId, ?int $userId = null): StudentAcademicRecord
    {
        return DB::transaction(function () use ($data, $collegeId, $userId): StudentAcademicRecord {
            $student = $this->lockStudent($collegeId, (int) $data['student_id']);

            $context = $this->resolveContext($data, $collegeId, $student);

            // One live record per student + year + term.
            $duplicate = StudentAcademicRecord::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->where('student_id', $student->id)
                ->where('academic_year_id', $context['academic_year_id'])
                ->where('academic_term_id', $context['academic_term_id'])
                ->whereNull('deleted_at')
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'academic_term_id' => 'An academic record already exists for this student, academic year and term.',
                ]);
            }

            $record = StudentAcademicRecord::create($context + [
                'college_id' => $collegeId,
                'student_id' => $student->id,
                'academic_status' => $data['academic_status'] ?? 'enrolled',
                'promotion_status' => $data['promotion_status'] ?? 'not_applicable',
                'completion_status' => $data['completion_status'] ?? 'pending',
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->audit->record('student_academic_record.created', $record, [], $record->only(self::AUDITED));

            return $record;
        });
    }

    public function update(StudentAcademicRecord $record, array $data, int $collegeId, ?int $userId = null): StudentAcademicRecord
    {
        return DB::transaction(function () use ($record, $data, $collegeId, $userId): StudentAcademicRecord {
            if ((int) $record->college_id !== (int) $collegeId) {
                abort(404, 'Academic record not found in this college context.');
            }

            $student = $this->lockStudent($collegeId, (int) $record->student_id);

            // Merge then re-validate: a partially supplied update can never
            // leave the record pointing at a foreign or inconsistent context.
            $merged = array_merge($record->only([
                'academic_year_id', 'academic_term_id', 'program_id', 'section_id', 'enrollment_id',
            ]), array_filter($data, fn ($key) => in_array($key, [
                'academic_year_id', 'academic_term_id', 'program_id', 'section_id', 'enrollment_id',
            ], true), ARRAY_FILTER_USE_KEY));

            $context = $this->resolveContext($merged, $collegeId, $student);

            $duplicate = StudentAcademicRecord::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->where('student_id', $student->id)
                ->where('academic_year_id', $context['academic_year_id'])
                ->where('academic_term_id', $context['academic_term_id'])
                ->whereNull('deleted_at')
                ->whereKeyNot($record->id)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'academic_term_id' => 'Another academic record already exists for this student, academic year and term.',
                ]);
            }

            $old = $record->only(self::AUDITED);

            $changes = $context;
            foreach (['academic_status', 'promotion_status', 'completion_status'] as $key) {
                if (array_key_exists($key, $data) && $data[$key] !== null) {
                    $changes[$key] = $data[$key];
                }
            }
            // Remarks may legitimately be cleared, so presence (not emptiness) decides.
            if (array_key_exists('remarks', $data)) {
                $changes['remarks'] = $data['remarks'];
            }
            $changes['updated_by'] = $userId;

            $record->update($changes);

            $this->audit->record('student_academic_record.updated', $record, $old, $record->only(self::AUDITED));

            return $record;
        });
    }

    public function delete(StudentAcademicRecord $record): void
    {
        $snapshot = $record->only(self::AUDITED);

        // Soft delete: progression history is never destroyed.
        $record->delete();

        $this->audit->record('student_academic_record.deleted', $record, $snapshot, []);
    }

    /**
     * Lock the student row: serialises duplicate detection and proves the
     * student belongs to the active college (foreign id → 404, not 403).
     */
    private function lockStudent(int $collegeId, int $studentId): Student
    {
        $student = Student::withoutGlobalScopes()
            ->where('college_id', $collegeId)
            ->whereKey($studentId)
            ->lockForUpdate()
            ->first();

        if (! $student) {
            abort(404, 'Student not found in this college context.');
        }

        return $student;
    }

    /**
     * Re-resolve and contextually validate every reference in the payload.
     *
     * @return array{academic_year_id:int,academic_term_id:?int,program_id:?int,section_id:?int,enrollment_id:?int}
     */
    private function resolveContext(array $data, int $collegeId, Student $student): array
    {
        $yearId = (int) ($data['academic_year_id'] ?? 0);
        $year = AcademicYear::query()->find($yearId);

        if (! $year) {
            abort(404, 'Academic year not found in this college context.');
        }

        $termId = $data['academic_term_id'] ?? null;
        $term = null;
        if ($termId) {
            // Tenant-scoped by CollegeScope, and must belong to the chosen year.
            $term = AcademicTerm::query()->where('academic_year_id', $year->id)->find($termId);

            if (! $term) {
                throw ValidationException::withMessages([
                    'academic_term_id' => 'The selected academic term is not valid for the selected academic year in this college.',
                ]);
            }
        }

        $programId = $data['program_id'] ?? null;
        if ($programId && ! Program::query()->find($programId)) {
            abort(404, 'Program not found in this college context.');
        }

        $sectionId = $data['section_id'] ?? null;
        if ($sectionId) {
            $sectionQuery = Section::query()->where('academic_year_id', $year->id);
            if ($programId) {
                $sectionQuery->where('program_id', $programId);
            }
            $section = $sectionQuery->find($sectionId);

            if (! $section) {
                throw ValidationException::withMessages([
                    'section_id' => 'The selected section is not valid for the selected academic year and program in this college.',
                ]);
            }
        }

        $enrollmentId = $data['enrollment_id'] ?? null;
        if ($enrollmentId) {
            $enrollment = StudentEnrollment::query()
                ->where('student_id', $student->id)
                ->find($enrollmentId);

            if (! $enrollment) {
                throw ValidationException::withMessages([
                    'enrollment_id' => 'The selected enrollment does not belong to this student in this college.',
                ]);
            }
        }

        return [
            'academic_year_id' => $year->id,
            'academic_term_id' => $term?->id,
            'program_id' => $programId ? (int) $programId : null,
            'section_id' => $sectionId ? (int) $sectionId : null,
            'enrollment_id' => $enrollmentId ? (int) $enrollmentId : null,
        ];
    }
}
