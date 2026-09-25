<?php

namespace App\Domain\Communication\Support;

use Illuminate\Support\Str;

/**
 * Extensible category labels for notices and notifications.
 *
 * `notice_type` and `notification_type` are stored as normalised slugs
 * (lower-case, `[a-z0-9_]`), NOT as a closed enum: a college may introduce any
 * category without a migration. The constants below are only SUGGESTIONS
 * offered by the forms; any other well-formed label is accepted and shown
 * through a headline fallback ("sports_event" → "Sports Event").
 */
final class CommunicationTypes
{
    /** Validation pattern for a normalised type slug. */
    public const PATTERN = '/^[a-z0-9]+(?:_[a-z0-9]+)*$/';

    public const MAX_LENGTH = 50;

    /** @var array<string, string> */
    public const NOTICE_TYPES = [
        'general' => 'General',
        'academic' => 'Academic',
        'examination' => 'Examination',
        'administrative' => 'Administrative',
        'event' => 'Event',
        'holiday' => 'Holiday',
        'other' => 'Other',
    ];

    /** @var array<string, string> */
    public const NOTIFICATION_TYPES = [
        'general' => 'General',
        'alert' => 'Alert',
        'reminder' => 'Reminder',
        'announcement' => 'Announcement',
        'task' => 'Task',
        'system' => 'System',
    ];

    /**
     * Normalise free text to the stored slug form. Non-strings are returned
     * untouched so the validator can reject them.
     */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $slug = strtolower(trim($value));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '_', $slug);

        return trim($slug, '_');
    }

    /**
     * @param  array<string, string>  $suggestions
     */
    public static function label(?string $type, array $suggestions): string
    {
        if ($type === null || $type === '') {
            return '—';
        }

        return $suggestions[$type] ?? Str::headline($type);
    }
}
