<?php

namespace App\Domain\Student\Services;

use App\Http\Requests\Student\StoreStudentRequest;
use App\Models\AdmissionDocumentType;
use App\Models\Student;
use App\Models\StudentRegistrationDraft;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Encrypted, owner/college-scoped working copies. Attachment metadata is kept
 * with the draft; blobs are private and become StudentDocument rows only when
 * the registration is submitted. No declaration creates a document record.
 */
final class StudentRegistrationDraftService
{
    public function __construct(private readonly StudentDocumentService $documents) {}

    public function findOwned(string $id, int $collegeId, int $actorId): StudentRegistrationDraft
    {
        return StudentRegistrationDraft::query()
            ->where('college_id', $collegeId)
            ->where('created_by', $actorId)
            ->whereKey($id)
            ->firstOrFail();
    }

    /** Save incomplete profile fields without relaxing final submission rules. */
    public function save(Request $request, string $id, int $collegeId, int $actorId): StudentRegistrationDraft
    {
        $keys = array_diff(array_keys((new StoreStudentRequest())->rules()), ['status', 'photo']);
        $rules = [];
        foreach ($keys as $key) {
            // Drafts may be incomplete or invalid, but may not contain arbitrary
            // structures or unbounded data. Full validation runs on submission.
            $rules[$key] = ['nullable', 'string', 'max:4000'];
        }
        $rules['registration_document_type_ids'] = ['nullable', 'array'];
        $rules['registration_document_type_ids.*'] = [
            'required', 'integer', 'distinct',
            Rule::exists('admission_document_types', 'id')
                ->where('college_id', $collegeId)->where('status', 'active')->whereNull('deleted_at'),
        ];

        $input = $request->input();
        foreach ($keys as $key) {
            if (isset($input[$key]) && (is_int($input[$key]) || is_float($input[$key]))) {
                $input[$key] = (string) $input[$key];
            }
        }
        if ($request->has('registration_declarations_present') && ! isset($input['registration_document_type_ids'])) {
            $input['registration_document_type_ids'] = [];
        }
        $data = Validator::make($input, $rules)->validate();
        $clearAadhaar = $request->boolean('registration_clear_aadhaar');
        $clearGovtId = $request->boolean('registration_clear_govt_id');
        $submittedGovtNumber = $data['govt_id_number'] ?? null;

        return DB::transaction(function () use ($id, $collegeId, $actorId, $data, $clearAadhaar, $clearGovtId, $submittedGovtNumber): StudentRegistrationDraft {
            $draft = $this->lockOrCreate($id, $collegeId, $actorId);
            $this->assertEditable($draft);
            $previous = $draft->data ?? [];

            // The form intentionally never echoes stored identity numbers.
            // Blank on resume therefore means keep the encrypted draft value.
            foreach (['aadhaar_number', 'govt_id_number'] as $key) {
                if (blank($data[$key] ?? null) && filled($previous[$key] ?? null)) {
                    $data[$key] = $previous[$key];
                }
            }
            if ($clearAadhaar) {
                $data['aadhaar_number'] = null;
            }
            if ($clearGovtId || (array_key_exists('govt_id_type', $data) && blank($data['govt_id_type']) && blank($submittedGovtNumber))) {
                $data['govt_id_type'] = null;
                $data['govt_id_number'] = null;
            }

            $draft->update(['data' => array_merge($previous, $data)]);

            return $draft;
        });
    }

    public function attach(Request $request, string $id, int $collegeId, int $actorId): array
    {
        $validated = Validator::make($request->all(), [
            'attachment_token' => ['required', 'uuid'],
            'attachment_type_id' => [
                'nullable', 'integer',
                Rule::exists('admission_document_types', 'id')
                    ->where('college_id', $collegeId)->where('status', 'active')->whereNull('deleted_at'),
            ],
            'attachment_title' => ['nullable', 'string', 'max:255'],
            'attachment_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ])->validate();

        $file = $request->file('attachment_file');
        $typeId = $validated['attachment_type_id'] ?? null;
        $type = $typeId ? AdmissionDocumentType::query()->where('college_id', $collegeId)->findOrFail($typeId) : null;
        $extension = $this->documents->validateUpload($file, $type);
        $path = $file->getRealPath();
        $hash = $path ? hash_file('sha256', $path) : false;
        if (! is_string($hash)) {
            throw ValidationException::withMessages(['attachment_file' => 'The file could not be read. Please try again.']);
        }
        $storedPath = null;

        try {
            return DB::transaction(function () use ($id, $collegeId, $actorId, $validated, $file, $type, $extension, $hash, &$storedPath): array {
                $draft = $this->lockOrCreate($id, $collegeId, $actorId);
                $this->assertEditable($draft);
                $attachments = $draft->attachments ?? [];

                foreach ($attachments as $attachment) {
                    // The same token protects retries; the content/type check also
                    // catches double clicks that were sent with a fresh token.
                    if ($attachment['id'] === $validated['attachment_token']
                        || ((int) $attachment['type_id'] === (int) ($type?->id) && hash_equals($attachment['sha256'], $hash))) {
                        return $this->visibleAttachments($attachments);
                    }
                }
                if (count($attachments) >= 10) {
                    throw ValidationException::withMessages(['attachment_file' => 'A registration may contain at most 10 files.']);
                }

                $directory = $this->directory($collegeId, $id);
                $storedPath = $file->storeAs($directory, Str::uuid()->toString().'.'.$extension, 'private');
                if (! is_string($storedPath) || $storedPath === '') {
                    throw ValidationException::withMessages(['attachment_file' => 'The file could not be saved. Please try again.']);
                }

                $filename = $file->getClientOriginalName();
                if (mb_strlen($filename) > 255) {
                    // Keep the validated extension for the final document write.
                    $filename = mb_substr($filename, 0, 254 - strlen($extension)).'.'.$extension;
                }
                $attachments[] = [
                    'id' => $validated['attachment_token'],
                    'type_id' => $type?->id,
                    'type_name' => $type?->name ?? 'Unclassified',
                    'title' => filled($validated['attachment_title'] ?? null) ? $validated['attachment_title'] : $filename,
                    'filename' => $filename,
                    'path' => $storedPath,
                    'sha256' => $hash,
                ];
                $draft->update(['attachments' => $attachments]);

                return $this->visibleAttachments($attachments);
            });
        } catch (Throwable $e) {
            if (is_string($storedPath) && $storedPath !== '') {
                Storage::disk('private')->delete($storedPath);
            }
            throw $e;
        }
    }

    public function remove(string $id, string $attachmentId, int $collegeId, int $actorId): array
    {
        return DB::transaction(function () use ($id, $attachmentId, $collegeId, $actorId): array {
            $draft = $this->lockOwned($id, $collegeId, $actorId);
            $this->assertEditable($draft);
            $kept = [];
            $removedPath = null;
            foreach ($draft->attachments ?? [] as $attachment) {
                if ($attachment['id'] === $attachmentId) {
                    $removedPath = $this->checkedPath($attachment['path'], $collegeId, $id);
                } else {
                    $kept[] = $attachment;
                }
            }
            $draft->update(['attachments' => $kept]);
            if ($removedPath !== null) {
                DB::afterCommit(fn () => Storage::disk('private')->delete($removedPath));
            }

            return $this->visibleAttachments($kept);
        });
    }

    /** Caller holds the draft row lock and owns the surrounding transaction. */
    public function transfer(StudentRegistrationDraft $draft, Student $student, int $actorId, array &$newPaths): array
    {
        $transferred = [];
        $stagedPaths = [];
        $collegeId = (int) $draft->college_id;
        foreach ($draft->attachments ?? [] as $attachment) {
            $path = $this->checkedPath($attachment['path'], $collegeId, $draft->id);
            if (! Storage::disk('private')->exists($path)) {
                throw ValidationException::withMessages(['attachment_file' => 'A draft attachment is missing. Remove it and attach the file again.']);
            }

            // This is an already validated private file, not a client-supplied
            // path. The document service rechecks current type limits and writes
            // the final file under its normal student/college UUID path.
            $file = new UploadedFile(Storage::disk('private')->path($path), $attachment['filename'], null, null, true);
            Validator::make(['file' => $file], [
                'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            ])->validate();
            $document = $this->documents->store([
                'student_id' => $student->id,
                'document_type_id' => $attachment['type_id'],
                'title' => $attachment['title'],
                'remarks' => 'Uploaded with registration submission.',
            ], $file, $collegeId, $actorId);
            $newPaths[] = $document->file_path;
            $stagedPaths[] = $path;
            $transferred[] = Arr::only($attachment, ['id', 'type_id', 'type_name', 'title', 'filename']) + [
                'student_document_id' => $document->id,
            ];
        }

        if ($stagedPaths !== []) {
            DB::afterCommit(fn () => Storage::disk('private')->delete($stagedPaths));
        }

        return $transferred;
    }

    public function lockOwned(string $id, int $collegeId, int $actorId): StudentRegistrationDraft
    {
        return StudentRegistrationDraft::query()->where('college_id', $collegeId)
            ->where('created_by', $actorId)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function visibleAttachments(array $attachments): array
    {
        return array_map(fn (array $attachment): array => [
            'id' => $attachment['id'],
            'type_id' => $attachment['type_id'],
            'type_name' => $attachment['type_name'],
            'title' => $attachment['title'],
            'filename' => $attachment['filename'],
            'status' => 'Attached to draft',
        ], $attachments);
    }

    private function lockOrCreate(string $id, int $collegeId, int $actorId): StudentRegistrationDraft
    {
        // insertOrIgnore + row lock makes the first two concurrent requests for
        // the same client UUID converge on one draft, never on another owner.
        $draft = new StudentRegistrationDraft([
            'id' => $id, 'college_id' => $collegeId, 'created_by' => $actorId,
            'data' => [], 'attachments' => [], 'status' => 'draft',
        ]);
        DB::table('student_registration_drafts')->insertOrIgnore($draft->getAttributes() + [
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->lockOwned($id, $collegeId, $actorId);
    }

    private function assertEditable(StudentRegistrationDraft $draft): void
    {
        if ($draft->status !== 'draft') {
            throw ValidationException::withMessages(['draft' => 'This registration has already been submitted.']);
        }
    }

    private function directory(int $collegeId, string $id): string
    {
        return 'student-registration-drafts/'.$collegeId.'/'.$id;
    }

    private function checkedPath(string $path, int $collegeId, string $id): string
    {
        StudentDocumentService::assertSafePath($path);
        abort_unless(str_starts_with($path, $this->directory($collegeId, $id).'/'), 403);

        return $path;
    }
}
