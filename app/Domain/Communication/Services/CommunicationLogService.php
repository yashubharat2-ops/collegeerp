<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\Support\CommunicationChannels;
use App\Domain\Communication\Support\CommunicationLogStatus;
use App\Domain\Communication\Support\NotificationRecipients;
use App\Domain\Foundation\Scopes\CollegeScope;
use App\Models\College;
use App\Models\CommunicationLog;
use App\Models\CommunicationTemplate;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CommunicationLogService — SMS / e-mail logs (Communication Management,
 * Phase 2).
 *
 * Phase 2 implements NO gateway: this service only RECORDS what happened to
 * a message. It is the single writer of `communication_logs`; the UI is
 * read-only, so a log is immutable once created except for the explicit
 * status transitions below (queued → sent → delivered, or → failed), which
 * other modules / a future gateway integration call.
 *
 * Guarantees:
 *   - college_id always comes from the ACTIVE tenant;
 *   - a referenced template must belong to the SAME college and match the
 *     channel; subject / content are SNAPSHOT on the log, so later template
 *     edits never rewrite history;
 *   - an optional recipient_type + recipient_id must reference an EXISTING
 *     user / student / staff record of the same college;
 *   - SMS logs carry no subject; a failed log always carries a reason;
 *   - terminal (delivered / failed) logs can never change again;
 *   - every write is transactional and audited.
 */
class CommunicationLogService
{
    public const AUDITED = [
        'channel', 'recipient', 'recipient_type', 'recipient_id', 'communication_template_id',
        'subject', 'status', 'provider_reference', 'sent_at', 'delivered_at', 'failure_reason',
    ];

    public function __construct(private readonly AuditLogService $audit) {}

    /**
     * Record one communication attempt.
     *
     * @param  array<string, mixed>  $data  channel, recipient, content, status?, subject?,
     *                                      template?/communication_template_id?, recipient_type?,
     *                                      recipient_id?, provider_reference?, failure_reason?,
     *                                      sent_at?, delivered_at?
     */
    public function log(College $college, array $data, ?User $actor = null): CommunicationLog
    {
        $collegeId = (int) $college->getKey();
        $this->assertTenantId($collegeId);

        $channel = $data['channel'] ?? null;

        if (! CommunicationChannels::isValid($channel)) {
            throw ValidationException::withMessages(['channel' => 'The selected channel is invalid.']);
        }

        $status = (string) ($data['status'] ?? CommunicationLogStatus::QUEUED);

        if (! CommunicationLogStatus::isValid($status)) {
            throw ValidationException::withMessages(['status' => 'The selected status is invalid.']);
        }

        $recipient = trim((string) ($data['recipient'] ?? ''));

        if ($recipient === '') {
            throw ValidationException::withMessages(['recipient' => 'A recipient is required.']);
        }

        $content = rtrim((string) ($data['content'] ?? ''));

        if ($content === '') {
            throw ValidationException::withMessages(['content' => 'A message body is required.']);
        }

        $template = $this->resolveTemplate($data, $collegeId, (string) $channel);
        $recipientLink = $this->resolveRecipientLink($data, $collegeId);

        $subject = array_key_exists('subject', $data) ? trim((string) ($data['subject'] ?? '')) : '';
        $subject = $subject === '' ? null : $subject;

        if (! CommunicationChannels::supportsSubject((string) $channel)) {
            $subject = null; // SMS has no subject line
        }

        $failureReason = trim((string) ($data['failure_reason'] ?? ''));

        if ($status === CommunicationLogStatus::FAILED && $failureReason === '') {
            throw ValidationException::withMessages(['failure_reason' => 'A failure reason is required for a failed communication.']);
        }

        $now = Carbon::now();

        return DB::transaction(function () use ($collegeId, $channel, $recipient, $recipientLink, $template, $subject, $content, $status, $data, $failureReason, $now, $actor): CommunicationLog {
            $log = new CommunicationLog;
            $log->college_id = $collegeId;
            $log->channel = (string) $channel;
            $log->recipient = $recipient;
            $log->recipient_type = $recipientLink['type'];
            $log->recipient_id = $recipientLink['id'];
            $log->communication_template_id = $template?->getKey();
            $log->subject = $subject;
            $log->content = $content;
            $log->status = $status;
            $log->provider_reference = $this->nullableString($data['provider_reference'] ?? null);
            $log->failure_reason = $status === CommunicationLogStatus::FAILED ? $failureReason : null;
            $log->sent_at = $this->timestamp($data['sent_at'] ?? null)
                ?? (in_array($status, [CommunicationLogStatus::SENT, CommunicationLogStatus::DELIVERED], true) ? $now : null);
            $log->delivered_at = $this->timestamp($data['delivered_at'] ?? null)
                ?? ($status === CommunicationLogStatus::DELIVERED ? $now : null);
            $log->created_by = $actor?->getKey();
            $log->save();

            $this->audit->record('communication_logs.created', $log, [], $log->only(self::AUDITED));

            return $log->refresh();
        });
    }

    /**
     * Queue a message rendered from a reusable template.
     *
     * @param  array<string, string|int|float|null>  $values  Placeholder values.
     */
    public function logFromTemplate(College $college, CommunicationTemplate $template, string $recipient, array $values = [], array $overrides = [], ?User $actor = null): CommunicationLog
    {
        return $this->log($college, array_merge([
            'channel' => $template->channel,
            'recipient' => $recipient,
            'communication_template_id' => $template->getKey(),
            'subject' => $template->renderSubject($values),
            'content' => $template->renderBody($values),
            'status' => CommunicationLogStatus::QUEUED,
        ], $overrides), $actor);
    }

    public function markSent(CommunicationLog $log, ?string $providerReference = null): CommunicationLog
    {
        return $this->transition($log, CommunicationLogStatus::SENT, [
            'provider_reference' => $providerReference,
            'sent_at' => Carbon::now(),
        ]);
    }

    public function markDelivered(CommunicationLog $log): CommunicationLog
    {
        return $this->transition($log, CommunicationLogStatus::DELIVERED, [
            'delivered_at' => Carbon::now(),
        ]);
    }

    public function markFailed(CommunicationLog $log, string $reason): CommunicationLog
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['failure_reason' => 'A failure reason is required for a failed communication.']);
        }

        return $this->transition($log, CommunicationLogStatus::FAILED, ['failure_reason' => $reason]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(CommunicationLog $log, string $to, array $attributes = []): CommunicationLog
    {
        $this->assertTenantId((int) $log->college_id);

        return DB::transaction(function () use ($log, $to, $attributes): CommunicationLog {
            $fresh = $this->lockRow($log);
            $from = (string) $fresh->status;

            if ($from === $to) {
                return $fresh; // idempotent
            }

            if (! CommunicationLogStatus::canTransition($from, $to)) {
                throw ValidationException::withMessages([
                    'status' => "A {$from} communication cannot become {$to}.",
                ]);
            }

            $before = $fresh->only(self::AUDITED);
            $fresh->status = $to;

            foreach ($attributes as $column => $value) {
                if ($value !== null) {
                    $fresh->{$column} = $value;
                }
            }

            // Delivery implies the message left the system.
            if ($to === CommunicationLogStatus::DELIVERED && $fresh->sent_at === null) {
                $fresh->sent_at = $fresh->delivered_at;
            }

            $fresh->save();

            $this->audit->record('communication_logs.'.$to, $fresh, $before, $fresh->only(self::AUDITED));

            return $fresh->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveTemplate(array $data, int $collegeId, string $channel): ?CommunicationTemplate
    {
        $template = $data['template'] ?? null;
        $templateId = $template instanceof CommunicationTemplate
            ? (int) $template->getKey()
            : (int) ($data['communication_template_id'] ?? 0);

        if ($templateId <= 0) {
            return null;
        }

        $record = CommunicationTemplate::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $collegeId)
            ->whereKey($templateId)
            ->first();

        if (! $record) {
            throw ValidationException::withMessages([
                'communication_template_id' => 'The selected template does not belong to the active college.',
            ]);
        }

        if ($record->channel !== $channel) {
            throw ValidationException::withMessages([
                'communication_template_id' => 'The selected template belongs to a different channel.',
            ]);
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: ?string, id: ?int}
     */
    private function resolveRecipientLink(array $data, int $collegeId): array
    {
        $type = $data['recipient_type'] ?? null;
        $id = $data['recipient_id'] ?? null;

        if ($type === null && $id === null) {
            return ['type' => null, 'id' => null];
        }

        if (! NotificationRecipients::isValidType($type)) {
            throw ValidationException::withMessages(['recipient_type' => 'The selected recipient type is invalid.']);
        }

        if (! NotificationRecipients::exists((string) $type, (int) $id, $collegeId)) {
            throw ValidationException::withMessages(['recipient_id' => 'The selected recipient does not belong to the active college.']);
        }

        return ['type' => (string) $type, 'id' => (int) $id];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function timestamp(mixed $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value);
    }

    private function lockRow(CommunicationLog $log): CommunicationLog
    {
        return CommunicationLog::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $log->college_id)
            ->whereKey($log->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertTenantId(int $collegeId): void
    {
        $active = app(TenantContext::class)->id();

        abort_unless($active !== null && (int) $active === $collegeId, 403);
    }
}
