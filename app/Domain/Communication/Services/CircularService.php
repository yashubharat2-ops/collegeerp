<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\Support\CommunicationTargets;
use App\Domain\Communication\Support\PublicationWorkflow;
use App\Domain\Foundation\Scopes\CollegeScope;
use App\Models\Circular;
use App\Models\College;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * CircularService — create / update / delete and the publication workflow of
 * Circulars (Communication Management, Phase 1). A separate module from
 * Notices with its own numbering rules.
 *
 * Guarantees:
 *   - college_id comes from the tenant context; created_by / updated_by from
 *     the authenticated user;
 *   - circular_number is unique within the college INCLUDING archived
 *     circulars (an issued number is never reused), and is frozen once the
 *     circular has left the draft stage;
 *   - status only moves along PublicationWorkflow::TRANSITIONS; an expired
 *     circular cannot be published; publishing without a schedule stamps
 *     publish_at with the publication moment; archived circulars are
 *     read-only;
 *   - attachments go through CommunicationAttachmentService (replaced files
 *     deleted after commit, new files cleaned up on failure);
 *   - every mutation is transactional, serialized per college and audited.
 */
class CircularService
{
    public const AUDITED = [
        'circular_number', 'title', 'subject', 'content', 'issue_date', 'publish_at', 'expires_at', 'status',
        'target_type', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size',
    ];

    private const AREA = CommunicationAttachmentService::AREA_CIRCULARS;

    private const DUPLICATE_NUMBER_MESSAGE = 'This circular number is already used in the active college (including archived circulars).';

    public function __construct(
        private readonly AuditLogService $audit,
        private readonly CommunicationAttachmentService $attachments,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated payload.
     */
    public function create(College $college, array $data, User $actor, ?UploadedFile $attachment = null): Circular
    {
        $collegeId = (int) $college->getKey();
        $this->assertTenantId($collegeId);

        $stored = $attachment ? $this->attachments->store($attachment, self::AREA, $collegeId) : null;

        try {
            return DB::transaction(function () use ($collegeId, $data, $actor, $stored): Circular {
                $this->lockCollege($collegeId);

                $circular = new Circular;
                $circular->college_id = $collegeId;
                $circular->circular_number = (string) ($data['circular_number'] ?? '');
                $this->fillEditable($circular, $data);
                $circular->status = PublicationWorkflow::DRAFT;
                $circular->created_by = $actor->getKey();
                $circular->updated_by = $actor->getKey();

                $this->assertUniqueNumber($circular);

                if ($stored) {
                    $this->applyAttachment($circular, $stored);
                }

                $this->persist($circular);

                $this->audit->record('circulars.created', $circular, [], $this->snapshot($circular));

                return $circular->refresh();
            });
        } catch (Throwable $e) {
            $this->discard($stored, $collegeId);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data  Validated payload.
     */
    public function update(Circular $circular, array $data, User $actor, ?UploadedFile $attachment = null, bool $removeAttachment = false): Circular
    {
        $this->assertTenant($circular);
        $collegeId = (int) $circular->college_id;

        $stored = $attachment ? $this->attachments->store($attachment, self::AREA, $collegeId) : null;
        $obsolete = null;

        try {
            $updated = DB::transaction(function () use ($circular, $collegeId, $data, $actor, $stored, $removeAttachment, &$obsolete): Circular {
                $this->lockCollege($collegeId);
                $fresh = $this->lockRow($circular);

                if ($fresh->isArchived()) {
                    throw ValidationException::withMessages([
                        'circular' => 'Archived circulars are read-only. Unpublish it back to draft before editing.',
                    ]);
                }

                $before = $this->snapshot($fresh);

                if (array_key_exists('circular_number', $data)) {
                    $number = strtoupper(trim((string) $data['circular_number']));

                    if ($number !== $fresh->circular_number && ! $fresh->isDraft()) {
                        throw ValidationException::withMessages([
                            'circular_number' => 'The circular number cannot be changed once the circular has been published.',
                        ]);
                    }

                    $fresh->circular_number = $number;
                }

                $this->fillEditable($fresh, $data);
                $this->assertUniqueNumber($fresh);

                if ($stored) {
                    $obsolete = $fresh->attachment_path;
                    $this->applyAttachment($fresh, $stored);
                } elseif ($removeAttachment && $fresh->hasAttachment()) {
                    $obsolete = $fresh->attachment_path;
                    $this->clearAttachment($fresh);
                }

                $fresh->updated_by = $actor->getKey();
                $this->persist($fresh);

                [$old, $new] = $this->diff($before, $this->snapshot($fresh));
                $this->audit->record('circulars.updated', $fresh, $old, $new);

                return $fresh->refresh();
            });
        } catch (Throwable $e) {
            $this->discard($stored, $collegeId);

            throw $e;
        }

        $this->discard($obsolete ? ['path' => $obsolete] : null, $collegeId);

        return $updated;
    }

    /**
     * Soft delete; the number stays reserved and the file is kept.
     */
    public function delete(Circular $circular, User $actor): void
    {
        $this->assertTenant($circular);

        DB::transaction(function () use ($circular, $actor): void {
            $this->lockCollege((int) $circular->college_id);
            $fresh = $this->lockRow($circular);

            $snapshot = $this->snapshot($fresh);

            $fresh->forceFill(['updated_by' => $actor->getKey()])->save();
            $fresh->delete();

            $this->audit->record('circulars.deleted', $fresh, $snapshot, []);
        });
    }

    public function publish(Circular $circular, User $actor): Circular
    {
        return $this->transition($circular, PublicationWorkflow::ACTION_PUBLISH, $actor);
    }

    public function unpublish(Circular $circular, User $actor): Circular
    {
        return $this->transition($circular, PublicationWorkflow::ACTION_UNPUBLISH, $actor);
    }

    public function archive(Circular $circular, User $actor): Circular
    {
        return $this->transition($circular, PublicationWorkflow::ACTION_ARCHIVE, $actor);
    }

    private function transition(Circular $circular, string $action, User $actor): Circular
    {
        $this->assertTenant($circular);

        return DB::transaction(function () use ($circular, $action, $actor): Circular {
            $this->lockCollege((int) $circular->college_id);
            $fresh = $this->lockRow($circular);
            $from = (string) $fresh->status;

            if (! PublicationWorkflow::canTransition($from, $action)) {
                throw ValidationException::withMessages([
                    'status' => sprintf('A %s circular cannot be %s.', $from, PublicationWorkflow::pastTense($action)),
                ]);
            }

            $old = ['status' => $from, 'publish_at' => $fresh->publish_at];

            if ($action === PublicationWorkflow::ACTION_PUBLISH) {
                if ($fresh->expires_at !== null && $fresh->expires_at->lte(now())) {
                    throw ValidationException::withMessages([
                        'expires_at' => 'This circular has already expired. Update its expiry date before publishing it.',
                    ]);
                }

                // Publishing without a schedule makes it effective right now.
                if ($fresh->publish_at === null) {
                    $fresh->publish_at = now();
                }
            }

            $fresh->status = PublicationWorkflow::target($action);
            $fresh->updated_by = $actor->getKey();
            $fresh->save();

            $this->audit->record('circulars.'.PublicationWorkflow::pastTense($action), $fresh, $old, [
                'status' => $fresh->status,
                'publish_at' => $fresh->publish_at,
            ]);

            return $fresh->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fillEditable(Circular $circular, array $data): void
    {
        foreach (['title', 'subject'] as $field) {
            if (array_key_exists($field, $data)) {
                $circular->{$field} = trim((string) $data[$field]);
            }
        }

        if (array_key_exists('content', $data)) {
            $circular->content = rtrim((string) $data['content']);
        }

        if (array_key_exists('issue_date', $data)) {
            $circular->issue_date = Carbon::parse((string) $data['issue_date'])->startOfDay();
        }

        foreach (['publish_at', 'expires_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $circular->{$field} = filled($data[$field]) ? Carbon::parse((string) $data[$field]) : null;
            }
        }

        if (array_key_exists('target_type', $data)) {
            $circular->target_type = (string) $data['target_type'];
        }

        $this->assertInvariants($circular);
    }

    private function assertInvariants(Circular $circular): void
    {
        $errors = [];

        if ($circular->circular_number === null || trim((string) $circular->circular_number) === '') {
            $errors['circular_number'] = 'A circular number is required.';
        }

        foreach (['title', 'subject'] as $field) {
            if (trim((string) $circular->{$field}) === '') {
                $errors[$field] = 'The '.$field.' is required.';
            }
        }

        if ($circular->issue_date === null) {
            $errors['issue_date'] = 'An issue date is required.';
        }

        if (! array_key_exists((string) $circular->target_type, CommunicationTargets::forCirculars())) {
            $errors['target_type'] = 'The selected target audience is invalid.';
        }

        if ($circular->expires_at !== null) {
            if ($circular->publish_at !== null && $circular->expires_at->lte($circular->publish_at)) {
                $errors['expires_at'] = 'The expiry date must be after the publish date.';
            } elseif ($circular->issue_date !== null && $circular->expires_at->lt($circular->issue_date)) {
                $errors['expires_at'] = 'The expiry date must not be before the issue date.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertUniqueNumber(Circular $circular): void
    {
        $duplicate = Circular::withoutGlobalScope(CollegeScope::class)
            ->withTrashed()
            ->where('college_id', $circular->college_id)
            ->where('circular_number', $circular->circular_number)
            ->when($circular->exists, fn ($query) => $query->whereKeyNot($circular->getKey()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['circular_number' => self::DUPLICATE_NUMBER_MESSAGE]);
        }
    }

    /**
     * @param  array{path: string, name: string, mime: string|null, size: int}  $stored
     */
    private function applyAttachment(Circular $circular, array $stored): void
    {
        $circular->attachment_path = $stored['path'];
        $circular->attachment_name = $stored['name'];
        $circular->attachment_mime = $stored['mime'];
        $circular->attachment_size = $stored['size'];
    }

    private function clearAttachment(Circular $circular): void
    {
        $circular->attachment_path = null;
        $circular->attachment_name = null;
        $circular->attachment_mime = null;
        $circular->attachment_size = null;
    }

    /**
     * @param  array{path: string}|null  $stored
     */
    private function discard(?array $stored, int $collegeId): void
    {
        if (! $stored) {
            return;
        }

        try {
            $this->attachments->delete($stored['path'], self::AREA, $collegeId);
        } catch (Throwable) {
            // Never mask the original outcome; the audit log keeps the path.
        }
    }

    private function persist(Circular $circular): void
    {
        try {
            $circular->save();
        } catch (QueryException $e) {
            $message = strtolower($e->getMessage());

            if (str_contains($message, 'unique') || str_contains($message, 'duplicate')) {
                // Racing insert with the same number; the unique index won.
                throw ValidationException::withMessages(['circular_number' => self::DUPLICATE_NUMBER_MESSAGE]);
            }

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Circular $circular): array
    {
        return $circular->only(self::AUDITED);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];

        foreach ($after as $key => $value) {
            $previous = $before[$key] ?? null;
            $a = $previous instanceof \DateTimeInterface ? $previous->format('Y-m-d H:i:s') : $previous;
            $b = $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value;

            if ($a !== $b) {
                $old[$key] = $previous;
                $new[$key] = $value;
            }
        }

        return [$old, $new];
    }

    private function lockRow(Circular $circular): Circular
    {
        return Circular::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $circular->college_id)
            ->whereKey($circular->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockCollege(int $collegeId): void
    {
        College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();
    }

    private function assertTenant(Circular $circular): void
    {
        $this->assertTenantId((int) $circular->college_id);
    }

    private function assertTenantId(int $collegeId): void
    {
        $active = app(TenantContext::class)->id();

        abort_unless($active !== null && (int) $active === $collegeId, 403);
    }
}
