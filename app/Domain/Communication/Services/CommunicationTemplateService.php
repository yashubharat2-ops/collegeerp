<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\Support\CommunicationChannels;
use App\Domain\Foundation\Scopes\CollegeScope;
use App\Models\College;
use App\Models\CommunicationTemplate;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CommunicationTemplateService — reusable SMS / e-mail templates
 * (Communication Management, Phase 2).
 *
 * Definitions only: nothing here contacts an SMS or e-mail provider.
 *
 * Guarantees:
 *   - college_id always comes from the ACTIVE tenant; created_by /
 *     updated_by from the acting user;
 *   - `code` is stored upper-case and is unique per college — enforced by a
 *     serialized check AND the unique index (race-safe);
 *   - SMS templates never keep a subject (it is normalised to null);
 *   - every mutation is transactional and audited.
 */
class CommunicationTemplateService
{
    public const AUDITED = ['name', 'code', 'channel', 'subject', 'body', 'status'];

    private const DUPLICATE_CODE_MESSAGE = 'This template code is already used in the active college.';

    public function __construct(private readonly AuditLogService $audit) {}

    /**
     * @param  array<string, mixed>  $data  Validated payload.
     */
    public function create(College $college, array $data, User $actor): CommunicationTemplate
    {
        $collegeId = (int) $college->getKey();
        $this->assertTenantId($collegeId);

        try {
            return DB::transaction(function () use ($collegeId, $data, $actor): CommunicationTemplate {
                $this->lockCollege($collegeId);

                $template = new CommunicationTemplate;
                $template->college_id = $collegeId;
                $this->fill($template, $data);
                $template->created_by = $actor->getKey();
                $template->updated_by = $actor->getKey();

                $this->assertUniqueCode($template);
                $template->save();

                $this->audit->record('communication_templates.created', $template, [], $template->only(self::AUDITED));

                return $template->refresh();
            });
        } catch (QueryException $exception) {
            throw $this->translateDuplicate($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data  Validated payload.
     */
    public function update(CommunicationTemplate $template, array $data, User $actor): CommunicationTemplate
    {
        $this->assertTenant($template);

        try {
            return DB::transaction(function () use ($template, $data, $actor): CommunicationTemplate {
                $this->lockCollege((int) $template->college_id);
                $fresh = $this->lockRow($template);
                $before = $fresh->only(self::AUDITED);

                $this->fill($fresh, $data);
                $fresh->updated_by = $actor->getKey();

                $this->assertUniqueCode($fresh);
                $fresh->save();

                $changed = array_values(array_filter(
                    self::AUDITED,
                    fn (string $field) => $before[$field] !== $fresh->{$field},
                ));
                $this->audit->record('communication_templates.updated', $fresh, Arr::only($before, $changed), $fresh->only($changed));

                return $fresh->refresh();
            });
        } catch (QueryException $exception) {
            throw $this->translateDuplicate($exception);
        }
    }

    public function delete(CommunicationTemplate $template, User $actor): void
    {
        $this->assertTenant($template);

        DB::transaction(function () use ($template): void {
            $fresh = $this->lockRow($template);
            $snapshot = $fresh->only(self::AUDITED);

            // Logs keep their own subject / content snapshot; the foreign key
            // is nulled by the database so history never changes.
            $fresh->delete();

            $this->audit->record('communication_templates.deleted', $fresh, $snapshot, []);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fill(CommunicationTemplate $template, array $data): void
    {
        if (array_key_exists('name', $data)) {
            $template->name = trim((string) $data['name']);
        }

        if (array_key_exists('code', $data)) {
            $template->code = (string) $data['code']; // mutator upper-cases
        }

        if (array_key_exists('channel', $data)) {
            $template->channel = (string) $data['channel'];
        }

        if (array_key_exists('subject', $data)) {
            $subject = trim((string) ($data['subject'] ?? ''));
            $template->subject = $subject === '' ? null : $subject;
        }

        if (array_key_exists('body', $data)) {
            $template->body = rtrim((string) $data['body']);
        }

        if (array_key_exists('status', $data)) {
            $template->status = (string) $data['status'];
        }

        // SMS has no subject line, whatever was posted.
        if (! CommunicationChannels::supportsSubject($template->channel)) {
            $template->subject = null;
        }

        $errors = [];

        if (trim((string) $template->name) === '') {
            $errors['name'] = 'A template name is required.';
        }

        if (trim((string) $template->code) === '') {
            $errors['code'] = 'A template code is required.';
        }

        if (! CommunicationChannels::isValid($template->channel)) {
            $errors['channel'] = 'The selected channel is invalid.';
        }

        if (trim((string) $template->body) === '') {
            $errors['body'] = 'A template body is required.';
        }

        if (! in_array($template->status, CommunicationTemplate::STATUSES, true)) {
            $errors['status'] = 'The selected status is invalid.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertUniqueCode(CommunicationTemplate $template): void
    {
        $exists = CommunicationTemplate::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $template->college_id)
            ->where('code', $template->code)
            ->when($template->exists, fn ($query) => $query->whereKeyNot($template->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
        }
    }

    private function translateDuplicate(QueryException $exception): \Throwable
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'comm_templates_college_code_unique') || str_contains($message, 'unique')) {
            return ValidationException::withMessages(['code' => self::DUPLICATE_CODE_MESSAGE]);
        }

        return $exception;
    }

    /** Serialize concurrent writes of the same college (code allocation). */
    private function lockCollege(int $collegeId): void
    {
        College::query()->whereKey($collegeId)->lockForUpdate()->firstOrFail();
    }

    private function lockRow(CommunicationTemplate $template): CommunicationTemplate
    {
        return CommunicationTemplate::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $template->college_id)
            ->whereKey($template->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertTenant(CommunicationTemplate $template): void
    {
        $this->assertTenantId((int) $template->college_id);
    }

    private function assertTenantId(int $collegeId): void
    {
        $active = app(TenantContext::class)->id();

        abort_unless($active !== null && (int) $active === $collegeId, 403);
    }
}
