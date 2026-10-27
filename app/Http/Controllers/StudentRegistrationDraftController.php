<?php

namespace App\Http\Controllers;

use App\Domain\Student\Services\RegistrationDocumentRequirements;
use App\Domain\Student\Services\StudentRegistrationDraftService;
use App\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudentRegistrationDraftController extends Controller
{
    public function save(Request $request, string $draft, StudentRegistrationDraftService $drafts): JsonResponse
    {
        [$collegeId, $actorId] = $this->context($request, $draft);

        return $this->respond(function () use ($drafts, $request, $draft, $collegeId, $actorId): array {
            $model = $drafts->save($request, $draft, $collegeId, $actorId);

            return ['attachments' => $drafts->visibleAttachments($model->attachments ?? [])];
        });
    }

    public function review(Request $request, string $draft, StudentRegistrationDraftService $drafts, RegistrationDocumentRequirements $requirements): JsonResponse
    {
        [$collegeId, $actorId] = $this->context($request, $draft);

        return $this->respond(function () use ($drafts, $requirements, $request, $draft, $collegeId, $actorId): array {
            $model = $drafts->save($request, $draft, $collegeId, $actorId);
            $data = $model->data ?? [];
            Validator::make($data, [
                'program_id' => ['required', 'integer', Rule::exists('programs', 'id')->where('college_id', $collegeId)->whereNull('deleted_at')],
            ])->validate();
            $missing = $requirements->missing($collegeId, (int) $data['program_id'], $data['registration_document_type_ids'] ?? []);
            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'registration_document_type_ids' => 'Select the required documents: '.$missing->pluck('name')->implode(', ').'.',
                ]);
            }

            return ['attachments' => $drafts->visibleAttachments($model->attachments ?? [])];
        });
    }

    public function attach(Request $request, string $draft, StudentRegistrationDraftService $drafts): JsonResponse
    {
        [$collegeId, $actorId] = $this->context($request, $draft);

        return $this->respond(function () use ($drafts, $request, $draft, $collegeId, $actorId): array {
            $drafts->save($request, $draft, $collegeId, $actorId);

            return ['attachments' => $drafts->attach($request, $draft, $collegeId, $actorId), 'next_token' => (string) Str::uuid()];
        });
    }

    public function remove(Request $request, string $draft, string $attachment, StudentRegistrationDraftService $drafts): JsonResponse
    {
        [$collegeId, $actorId] = $this->context($request, $draft);
        abort_unless(Str::isUuid($attachment), 404);

        return $this->respond(function () use ($drafts, $request, $draft, $attachment, $collegeId, $actorId): array {
            $drafts->save($request, $draft, $collegeId, $actorId);

            return ['attachments' => $drafts->remove($draft, $attachment, $collegeId, $actorId)];
        });
    }

    private function context(Request $request, string $draft): array
    {
        $this->authorize('create', Student::class);
        abort_unless(Str::isUuid($draft), 404);

        return [(int) app(TenantContext::class)->id(), (int) $request->user()->getKey()];
    }

    /** Explicit 422 responses also work with the application's JSON error handler. */
    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()->json(['ok' => true] + $callback());
        } catch (ValidationException $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
        }
    }
}
