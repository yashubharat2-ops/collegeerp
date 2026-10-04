<?php

namespace App\Domain\Student\Services;

use App\Models\AdmissionDocumentType;
use App\Models\Student;
use App\Models\StudentDocument;
use Illuminate\Support\Collection;

/**
 * The "bulk documents" pack: a consolidated, printable document summary for a
 * selection of students.
 *
 * It is a REPORT over data the module already owns — the student's existing
 * `student_documents` rows and the existing admission document-type master
 * (`AdmissionDocumentType`, reused exactly as the single document screen does).
 * Nothing is created, copied or stored: no pack table, no generated file, and no
 * second document checklist to keep in sync. What a college prints is therefore
 * always the current state of the records, including the verification status and
 * the required document types that are still missing.
 *
 * Both queries are tenant-scoped (CollegeScope on StudentDocument *and*
 * AdmissionDocumentType), so a pack can only ever contain the active college's
 * data.
 */
class StudentDocumentPackService
{
    /**
     * Build one pack row per student, in the order the students were given.
     *
     * @param Collection<int, Student> $students Already authorized, tenant-scoped students.
     * @return array<int, array{
     *     student: Student,
     *     documents: Collection<int, StudentDocument>,
     *     missingTypes: Collection<int, AdmissionDocumentType>,
     *     verified: int,
     *     pending: int,
     *     rejected: int
     * }>
     */
    public function packFor(Collection $students): array
    {
        $studentIds = $students->pluck('id')->all();

        // Two queries for the whole pack (documents + required types), never one
        // per student: a 200-student pack must not fire 200 queries.
        $documentsByStudent = StudentDocument::query()
            ->with(['documentType:id,name,code', 'verifiedBy:id,name'])
            ->whereIn('student_id', $studentIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('student_id');

        $requiredTypes = AdmissionDocumentType::query()
            ->where('is_required', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code']);

        $pack = [];

        foreach ($students as $student) {
            /** @var Collection<int, StudentDocument> $documents */
            $documents = $documentsByStudent->get($student->id) ?? new Collection();

            // Normalise to int: drivers differ on whether an id column hydrates
            // as int or string, and the comparison below is strict.
            $presentTypeIds = $documents
                ->pluck('document_type_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->all();

            $pack[$student->id] = [
                'student' => $student,
                'documents' => $documents->values(),
                'missingTypes' => $requiredTypes
                    ->reject(fn (AdmissionDocumentType $type) => in_array((int) $type->id, $presentTypeIds, true))
                    ->values(),
                'verified' => $documents->where('verification_status', 'verified')->count(),
                'pending' => $documents->where('verification_status', 'pending')->count(),
                'rejected' => $documents->where('verification_status', 'rejected')->count(),
            ];
        }

        return $pack;
    }
}
