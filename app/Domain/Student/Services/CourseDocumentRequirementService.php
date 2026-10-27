<?php

namespace App\Domain\Student\Services;

use App\Models\AdmissionDocumentType;
use App\Models\StudentDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Course-wise document requirements for the registration wizard.
 *
 * This wraps the configuration that ALREADY exists instead of replicating it:
 *
 *   `admission_document_types`          — the per-college document master
 *                                         (code, name, allowed extensions/MIMEs,
 *                                         max size, global `is_required`);
 *   `admission_document_type_program`   — the per-programme override
 *                                         (`is_required`, `can_submit_later`).
 *
 * Resolution rule (one rule, stated once):
 *   1. an explicit pivot row for the selected programme wins, including an
 *      explicit `is_required = false` ("optional for this course");
 *   2. otherwise the type's global `is_required` applies;
 *   3. a type with no pivot row and no global flag is optional.
 *
 * Nothing here marks a document "verified". Verification is a human decision
 * taken in the Documents module by an authorised user; an upload is always
 * `pending`, which is why {@see statusFor()} reports "uploaded" and
 * "verified" as different states.
 */
class CourseDocumentRequirementService
{
    public const STATUS_REQUIRED = 'required';
    public const STATUS_OPTIONAL = 'optional';
    public const STATUS_DEFERRED = 'deferred';
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_MISSING = 'missing';

    /**
     * All document types available to the college, with the programme-specific
     * requirement resolved for each.
     *
     * @return Collection<int, array{type: AdmissionDocumentType, is_required: bool, can_submit_later: bool, source: string}>
     */
    public function forProgram(?int $programId, int $collegeId): Collection
    {
        $types = AdmissionDocumentType::query()
            ->where('college_id', $collegeId)
            // An inactive document type is not demanded from an applicant.
            ->where(fn ($q) => $q->where('status', 'active')->orWhereNull('status'))
            ->orderBy('name')
            ->get();

        if ($types->isEmpty()) {
            return collect();
        }

        // The programme-specific configuration, read from the existing pivot.
        // A direct pivot query keeps the tenant filter explicit and avoids
        // guessing which table a bare `program_id` would resolve to.
        $pivot = $programId === null
            ? collect()
            : DB::table('admission_document_type_program')
                ->where('college_id', $collegeId)
                ->where('program_id', $programId)
                ->get()
                ->keyBy('document_type_id');

        return $types->map(function (AdmissionDocumentType $type) use ($pivot): array {
            $row = $pivot->get($type->id);

            return [
                'type' => $type,
                'is_required' => $row !== null ? (bool) $row->is_required : (bool) $type->is_required,
                'can_submit_later' => $row !== null ? (bool) $row->can_submit_later : false,
                'source' => $row !== null ? 'program' : ($type->is_required ? 'global' : 'optional'),
            ];
        });
    }

    /**
     * The types that must be attached before the registration can be submitted.
     *
     * A "deferred" document (required by the course but accepted after
     * admission) is deliberately NOT in this list — that is the whole point of
     * the flag, and blocking on it would make the flag meaningless.
     *
     * @return Collection<int, AdmissionDocumentType>
     */
    public function requiredBeforeSubmission(?int $programId, int $collegeId): Collection
    {
        return $this->forProgram($programId, $collegeId)
            ->filter(fn (array $row) => $row['is_required'] && ! $row['can_submit_later'])
            ->map(fn (array $row) => $row['type'])
            ->values();
    }

    /**
     * Requirements that are not satisfied by the uploaded type ids.
     *
     * @param  array<int, int|null>  $uploadedTypeIds
     * @return Collection<int, AdmissionDocumentType>
     */
    public function missingRequired(?int $programId, int $collegeId, array $uploadedTypeIds): Collection
    {
        $uploaded = array_values(array_unique(array_filter(array_map('intval', $uploadedTypeIds))));

        return $this->requiredBeforeSubmission($programId, $collegeId)
            ->reject(fn (AdmissionDocumentType $type) => in_array((int) $type->id, $uploaded, true))
            ->values();
    }

    /**
     * The checklist shown on the review step and above the upload list:
     * required / optional / deferred, plus what has been uploaded and whether a
     * human has verified it.
     *
     * @param  Collection<int, StudentDocument|\App\Models\StudentRegistrationDraftDocument>  $documents
     * @return Collection<int, array<string, mixed>>
     */
    public function checklist(?int $programId, int $collegeId, Collection $documents): Collection
    {
        $byType = $documents
            ->filter(fn ($document) => $document->document_type_id !== null)
            ->groupBy(fn ($document) => (int) $document->document_type_id);

        return $this->forProgram($programId, $collegeId)->map(function (array $row) use ($byType): array {
            /** @var AdmissionDocumentType $type */
            $type = $row['type'];
            $files = $byType->get((int) $type->id, collect());

            $uploaded = $files->isNotEmpty();
            $verified = $files->contains(fn ($document) => ($document->verification_status ?? 'pending') === 'verified');

            $status = match (true) {
                $verified => self::STATUS_VERIFIED,
                $uploaded => self::STATUS_UPLOADED,
                $row['is_required'] && $row['can_submit_later'] => self::STATUS_DEFERRED,
                $row['is_required'] => self::STATUS_MISSING,
                default => self::STATUS_OPTIONAL,
            };

            return [
                'type' => $type,
                'is_required' => $row['is_required'],
                'can_submit_later' => $row['can_submit_later'],
                'source' => $row['source'],
                'status' => $status,
                'uploaded' => $uploaded,
                'verified' => $verified,
                'documents' => $files->values(),
            ];
        });
    }

    /** Human label for a checklist status. */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_REQUIRED => 'Required',
            self::STATUS_OPTIONAL => 'Optional',
            self::STATUS_DEFERRED => 'May be submitted later',
            self::STATUS_UPLOADED => 'Uploaded — awaiting verification',
            self::STATUS_VERIFIED => 'Verified',
            self::STATUS_MISSING => 'Missing — required',
            default => ucfirst($status),
        };
    }
}
