<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\Support\CommunicationPriority;
use App\Domain\Communication\Support\CommunicationTargets;
use App\Domain\Communication\Support\CommunicationTypes;
use App\Domain\Communication\Support\PublicationWorkflow;
use App\Domain\Foundation\Scopes\CollegeScope;
use App\Models\College;
use App\Models\Notice;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * NoticeService — create / update / delete and the publication workflow of
 * Notices / Announcements (Communication Management, Phase 1).
 *
 * Guarantees:
 *   - a notice always belongs to the ACTIVE college: college_id comes from
 *     the tenant context, never from request data;
 *   - created_by / updated_by are stamped from the authenticated user;
 *   - status is only changed by publish / unpublish / archive, and only
 *     along PublicationWorkflow::TRANSITIONS; an expired notice cannot be
 *     published; archived notices are read-only;
 *   - entity targets (department / program / section) must exist in the
 *     same college — re-checked here even though the Form Request validates;
 *   - the slug is unique per college (archived rows included) and is only
 *     regenerated while the notice is a draft;
 *   - attachments are stored through CommunicationAttachmentService; a
 *     replaced / removed file is deleted only after the transaction commits,
 *     and a newly stored file is cleaned up if the transaction fails;
 *   - every mutation runs in a transaction serialized per college and is
 *     audited (changed fields only on update).
 */
class NoticeService
{
    public const AUDITED = [
        'title', 'slug', 'notice_type', 'content', 'publish_at', 'expires_at', 'status', 'priority',
        'target_type', 'target_id', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size',
    ];

    private const AREA = CommunicationAttachmentService::AREA_NOTICES;

    public function __construct(
        private readonly AuditLogService $audit,
        private readonly CommunicationAttachmentService $attachments,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated payload.
     */
    public function create(College $college, array $data, User $actor, ?UploadedFile $attachment = null): Notice
    {
        $collegeId = (int) $college->getKey();
        $this->assertTenantId($collegeId);

        $stored = $attachment ? $this->attachments->store($attachment, self::AREA, $collegeId) : null;

        try {
            return DB::transaction(function () use ($collegeId, $data, $actor, $stored): Notice {
                $this->lockCollege($collegeId);

                $notice = new Notice;
                $notice->college_id = $collegeId;
                $this->fillEditable($notice, $data);
                $notice->status = PublicationWorkflow::DRAFT;
                $notice->slug = $this->uniqueSlug($collegeId, (string) $notice->title);
                $notice->created_by = $actor->getKey();
                $notice->updated_by = $actor->getKey();

                if ($stored) {
                    $this->applyAttachment($notice, $stored);
                }

                $this->persist($notice);

                $this->audit->record('notices.created', $notice, [], $this->snapshot($notice));

                return $notice->refresh();
            });
        } catch (Throwable $e) {
            $this->discard($stored, $collegeId);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data  Validated payload.
     */
    public function update(Notice $notice, array $data, User $actor, ?UploadedFile $attachment = null, bool $removeAttachment = false): Notice
    {
        $this->assertTenant($notice);
        $collegeId = (int) $notice->college_id;

        $stored = $attachment ? $this->attachments->store($attachment, self::AREA, $collegeId) : null;
        $obsolete = null;

        try {
            $updated = DB::transaction(function () use ($notice, $collegeId, $data, $actor, $stored, $removeAttachment, &$obsolete): Notice {
                $this->lockCollege($collegeId);
                $fresh = $this->lockRow($notice);

                if ($fresh->isArchived()) {
                    throw ValidationException::withMessages([
                        'notice' => 'Archived notices are read-only. Unpublish it back to draft before editing.',
                    ]);
                }

                $before = $this->snapshot($fresh);
                $previousTitle = $fresh->title;

                $this->fillEditable($fresh, $data);

                // Slugs are stable once a notice has left the draft stage.
                if ($fresh->isDraft() && $fresh->title !== $previousTitle) {
                    $fresh->slug = $this->uniqueSlug($collegeId, (string) $fresh->title, (int) $fresh->getKey());
                }

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
                $this->audit->record('notices.updated', $fresh, $old, $new);

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
     * Soft delete. The attachment file is kept so the record stays restorable
     * and auditable; the full snapshot is written to the audit log.
     */
    public function delete(Notice $notice, User $actor): void
    {
        $this->assertTenant($notice);

        DB::transaction(function () use ($notice, $actor): void {
            $this->lockCollege((int) $notice->college_id);
            $fresh = $this->lockRow($notice);

            $snapshot = $this->snapshot($fresh);

            $fresh->forceFill(['updated_by' => $actor->getKey()])->save();
            $fresh->delete();

            $this->audit->record('notices.deleted', $fresh, $snapshot, []);
        });
    }

    public function publish(Notice $notice, User $actor): Notice
    {
        return $this->transition($notice, PublicationWorkflow::ACTION_PUBLISH, $actor);
    }

    public function unpublish(Notice $notice, User $actor): Notice
    {
        return $this->transition($notice, PublicationWorkflow::ACTION_UNPUBLISH, $actor);
    }

    public function archive(Notice $notice, User $actor): Notice
    {
        return $this->transition($notice, PublicationWorkflow::ACTION_ARCHIVE, $actor);
    }

    private function transition(Notice $notice, string $action, User $actor): Notice
    {
        $this->assertTenant($notice);

        return DB::transaction(function () use ($notice, $action, $actor): Notice {
            $this->lockCollege((int) $notice->college_id);
            $fresh = $this->lockRow($notice);
            $from = (string) $fresh->status;

            if (! PublicationWorkflow::canTransition($from, $action)) {
                throw ValidationException::withMessages([
                    'status' => sprintf('A %s notice cannot be %s.', $from, PublicationWorkflow::pastTense($action)),
                ]);
            }

            if ($action === PublicationWorkflow::ACTION_PUBLISH && $fresh->expires_at !== null && $fresh->expires_at->lte(now())) {
                throw ValidationException::withMessages([
                    'expires_at' => 'This notice has already expired. Update its expiry date before publishing it.',
                ]);
            }

            $fresh->status = PublicationWorkflow::target($action);
            $fresh->updated_by = $actor->getKey();
            $fresh->save();

            $this->audit->record('notices.'.PublicationWorkflow::pastTense($action), $fresh, ['status' => $from], ['status' => $fresh->status]);

            return $fresh->refresh();
        });
    }

    /**
     * Apply the editable fields of a validated payload and re-check every
     * invariant (the service is authoritative, the Form Request is the first
     * line of defence).
     *
     * @param  array<string, mixed>  $data
     */
    private function fillEditable(Notice $notice, array $data): void
    {
        if (array_key_exists('title', $data)) {
            $notice->title = trim((string) $data['title']);
        }

        if (array_key_exists('notice_type', $data)) {
            $notice->notice_type = (string) CommunicationTypes::normalize((string) $data['notice_type']);
        }

        if (array_key_exists('content', $data)) {
            $notice->content = rtrim((string) $data['content']);
        }

        if (array_key_exists('publish_at', $data)) {
            $notice->publish_at = Carbon::parse((string) $data['publish_at']);
        }

        if (array_key_exists('expires_at', $data)) {
            $notice->expires_at = filled($data['expires_at']) ? Carbon::parse((string) $data['expires_at']) : null;
        }

        if (array_key_exists('priority', $data)) {
            $notice->priority = (string) $data['priority'];
        }

        if (array_key_exists('target_type', $data)) {
            $notice->target_type = (string) $data['target_type'];
            $notice->target_id = CommunicationTargets::requiresEntity($notice->target_type)
                ? (int) ($data['target_id'] ?? 0)
                : null;
        }

        $this->assertInvariants($notice);
    }

    private function assertInvariants(Notice $notice): void
    {
        $errors = [];

        if (trim((string) $notice->title) === '') {
            $errors['title'] = 'A notice title is required.';
        }

        if (! preg_match(CommunicationTypes::PATTERN, (string) $notice->notice_type)) {
            $errors['notice_type'] = 'The notice type may only contain letters, numbers and separators.';
        }

        if (! in_array($notice->priority, CommunicationPriority::ALL, true)) {
            $errors['priority'] = 'The selected priority is invalid.';
        }

        if (! array_key_exists((string) $notice->target_type, CommunicationTargets::forNotices())) {
            $errors['target_type'] = 'The selected target audience is invalid.';
        } elseif (CommunicationTargets::requiresEntity($notice->target_type)
            && ! CommunicationTargets::findEntity((string) $notice->target_type, (int) $notice->target_id, (int) $notice->college_id)
        ) {
            $errors['target_id'] = 'The selected target does not exist in the active college.';
        }

        if ($notice->publish_at === null) {
            $errors['publish_at'] = 'A publish date is required.';
        } elseif ($notice->expires_at !== null && $notice->expires_at->lte($notice->publish_at)) {
            $errors['expires_at'] = 'The expiry date must be after the publish date.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * A slug unique within the college, archived notices included.
     */
    private function uniqueSlug(int $collegeId, string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug(Str::limit($title, 180, '')) ?: 'notice';
        $candidate = $base;

        for ($suffix = 2; $this->slugTaken($collegeId, $candidate, $ignoreId); $suffix++) {
            $candidate = $suffix <= 1000 ? $base.'-'.$suffix : $base.'-'.Str::lower(Str::random(8));
        }

        return $candidate;
    }

    private function slugTaken(int $collegeId, string $slug, ?int $ignoreId): bool
    {
        return Notice::withoutGlobalScope(CollegeScope::class)
            ->withTrashed()
            ->where('college_id', $collegeId)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    /**
     * @param  array{path: string, name: string, mime: string|null, size: int}  $stored
     */
    private function applyAttachment(Notice $notice, array $stored): void
    {
        $notice->attachment_path = $stored['path'];
        $notice->attachment_name = $stored['name'];
        $notice->attachment_mime = $stored['mime'];
        $notice->attachment_size = $stored['size'];
    }

    private function clearAttachment(Notice $notice): void
    {
        $notice->attachment_path = null;
        $notice->attachment_name = null;
        $notice->attachment_mime = null;
        $notice->attachment_size = null;
    }

    /**
     * Best-effort removal of a file that is no longer referenced.
     *
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

    private function persist(Notice $notice): void
    {
        try {
            $notice->save();
        } catch (QueryException $e) {
            $message = strtolower($e->getMessage());

            if (str_contains($message, 'unique') || str_contains($message, 'duplicate')) {
                // Racing insert with the same slug; the unique index won.
                throw ValidationException::withMessages(['title' => 'Another notice with this title was saved at the same moment. Please try again.']);
            }

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Notice $notice): array
    {
        return $notice->only(self::AUDITED);
    }

    /**
     * Only the fields that actually changed, as [old, new].
     *
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

            if ($this->normalizeForDiff($previous) !== $this->normalizeForDiff($value)) {
                $old[$key] = $previous;
                $new[$key] = $value;
            }
        }

        return [$old, $new];
    }

    private function normalizeForDiff(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value;
    }

    private function lockRow(Notice $notice): Notice
    {
        return Notice::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $notice->college_id)
            ->whereKey($notice->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockCollege(int $collegeId): void
    {
        College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();
    }

    private function assertTenant(Notice $notice): void
    {
        $this->assertTenantId((int) $notice->college_id);
    }

    private function assertTenantId(int $collegeId): void
    {
        $active = app(TenantContext::class)->id();

        abort_unless($active !== null && (int) $active === $collegeId, 403);
    }
}
