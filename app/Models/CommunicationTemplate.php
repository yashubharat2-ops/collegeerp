<?php

namespace App\Models;

use App\Domain\Communication\Support\CommunicationChannels;
use App\Domain\Foundation\Traits\BelongsToCollege;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reusable SMS / e-mail template (Communication Management, Phase 2).
 *
 * A DEFINITION only: nothing here sends anything and no external SMS or
 * e-mail provider is contacted. `code` is unique per college, `subject` is
 * null for the SMS channel, and `status` decides whether the template is
 * offered for reuse.
 *
 * Tenant isolation via BelongsToCollege + CollegeScope: another college's
 * template can never be listed, opened or edited.
 */
class CommunicationTemplate extends Model
{
    use BelongsToCollege;

    public const STATUSES = ['active', 'inactive'];

    public const CHANNELS = CommunicationChannels::LABELS;

    /** Placeholder syntax offered to template authors: {{ student_name }}. */
    public const PLACEHOLDER_PATTERN = '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/';

    protected $fillable = [
        'college_id',
        'name',
        'code',
        'channel',
        'subject',
        'body',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $attributes = [
        'channel' => CommunicationChannels::EMAIL,
        'status' => 'active',
    ];

    /** Codes are stored upper-case so uniqueness per college is unambiguous. */
    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $value));
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CommunicationLog::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function channelLabel(): string
    {
        return CommunicationChannels::label($this->channel);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSms(): bool
    {
        return $this->channel === CommunicationChannels::SMS;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), 'active');
    }

    public function scopeChannel(Builder $query, string $channel): Builder
    {
        return $query->where($this->qualifyColumn('channel'), $channel);
    }

    /**
     * Substitute {{ placeholders }} with the given values. Unknown
     * placeholders are left untouched so nothing is silently blanked.
     *
     * @param  array<string, string|int|float|null>  $values
     */
    public function render(string $text, array $values = []): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            fn (array $matches) => array_key_exists($matches[1], $values)
                ? (string) $values[$matches[1]]
                : $matches[0],
            $text,
        );
    }

    /** @param array<string, string|int|float|null> $values */
    public function renderBody(array $values = []): string
    {
        return $this->render((string) $this->body, $values);
    }

    /** @param array<string, string|int|float|null> $values */
    public function renderSubject(array $values = []): ?string
    {
        return $this->subject === null ? null : $this->render((string) $this->subject, $values);
    }

    /** @return array<int, string> */
    public function placeholders(): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, (string) $this->subject.' '.(string) $this->body, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}
