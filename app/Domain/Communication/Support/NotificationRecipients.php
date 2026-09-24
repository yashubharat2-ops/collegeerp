<?php

namespace App\Domain\Communication\Support;

use App\Models\College;
use App\Models\Faculty;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Recipient types of internal notifications (Communication Management,
 * Phase 1).
 *
 * A notification row stores `recipient_type` + `recipient_id` and REFERENCES
 * an existing master — it never copies user, student or staff data:
 *
 *   user    → users (must be an active member of the college, via user_college)
 *   student → students (Student master of the college, not archived)
 *   staff   → faculties (the Platform Faculty / Staff master reused by HR)
 *
 * Every check filters on the explicit college id, so a person of another
 * college can never be addressed, and a stale/forged id simply fails
 * validation. No global morph map is registered on purpose: that would
 * change the morph class of these models for every other module (audit log
 * subject types, etc.).
 */
final class NotificationRecipients
{
    public const USER = 'user';

    public const STUDENT = 'student';

    public const STAFF = 'staff';

    /** @var array<string, string> */
    public const TYPES = [
        self::USER => 'User',
        self::STUDENT => 'Student',
        self::STAFF => 'Staff',
    ];

    public static function isValidType(mixed $type): bool
    {
        return is_string($type) && array_key_exists($type, self::TYPES);
    }

    /**
     * Tenant-safe existence check for a prospective recipient.
     */
    public static function exists(string $type, int $id, int $collegeId): bool
    {
        if ($id <= 0 || $collegeId <= 0) {
            return false;
        }

        return match ($type) {
            self::USER => User::query()
                ->whereKey($id)
                ->where('is_active', true)
                ->whereHas('colleges', fn ($query) => $query->where('colleges.id', $collegeId))
                ->exists(),
            self::STUDENT => Student::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereNull('deleted_at')
                ->whereKey($id)
                ->exists(),
            self::STAFF => Faculty::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereNull('deleted_at')
                ->whereKey($id)
                ->exists(),
            default => false,
        };
    }

    /**
     * Resolve display labels for many notifications at once (at most one
     * query per recipient type). Archived people are still labelled so the
     * history stays readable.
     *
     * @param  iterable<int, object{recipient_type: string, recipient_id: int}>  $notifications
     * @return array<string, string> keyed by "type:id"
     */
    public static function labelsFor(iterable $notifications, int $collegeId): array
    {
        $ids = [];

        foreach ($notifications as $notification) {
            $ids[$notification->recipient_type][] = (int) $notification->recipient_id;
        }

        $labels = [];

        foreach ($ids as $type => $list) {
            foreach (self::recordsFor($type, array_values(array_unique($list)), $collegeId) as $record) {
                $labels[$type.':'.$record->getKey()] = self::labelFor($type, $record);
            }
        }

        return $labels;
    }

    /**
     * The referenced master record of one notification (null when it no
     * longer exists).
     */
    public static function resolve(string $type, int $id, int $collegeId): ?Model
    {
        return self::recordsFor($type, [$id], $collegeId)->first();
    }

    public static function labelFor(string $type, ?Model $record): string
    {
        if (! $record) {
            return 'Unavailable '.strtolower(self::TYPES[$type] ?? 'recipient');
        }

        $label = match ($type) {
            self::USER => trim((string) $record->getAttribute('name')).' <'.$record->getAttribute('email').'>',
            self::STUDENT => $record->fullName().' ('.$record->getAttribute('student_number').')',
            self::STAFF => trim((string) $record->getAttribute('full_name')).' ('.$record->getAttribute('employee_code').')',
            default => '#'.$record->getKey(),
        };

        return $record->getAttribute('deleted_at') ? $label.' — archived' : $label;
    }

    /**
     * Options for the notification form, grouped by type and capped per type.
     *
     * @return array<string, array<int, string>> type => [id => label]
     */
    public static function options(College $college, ?int $limit = null): array
    {
        $limit ??= (int) config('communication.recipient_option_limit', 500);

        $users = $college->users()
            ->where('users.is_active', true)
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->limit($limit)
            ->get(['users.id', 'users.name', 'users.email']);

        $students = Student::withoutGlobalScopes()
            ->where('college_id', $college->getKey())
            ->whereNull('deleted_at')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'college_id', 'student_number', 'first_name', 'middle_name', 'last_name', 'deleted_at']);

        $staff = Faculty::withoutGlobalScopes()
            ->where('college_id', $college->getKey())
            ->whereNull('deleted_at')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'college_id', 'employee_code', 'first_name', 'middle_name', 'last_name', 'deleted_at']);

        return [
            self::USER => $users->mapWithKeys(fn (User $user) => [$user->getKey() => self::labelFor(self::USER, $user)])->all(),
            self::STUDENT => $students->mapWithKeys(fn (Student $student) => [$student->getKey() => self::labelFor(self::STUDENT, $student)])->all(),
            self::STAFF => $staff->mapWithKeys(fn (Faculty $faculty) => [$faculty->getKey() => self::labelFor(self::STAFF, $faculty)])->all(),
        ];
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Model>
     */
    private static function recordsFor(string $type, array $ids, int $collegeId): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return match ($type) {
            // Users are platform accounts: the membership was validated when
            // the notification was created, so a later membership change must
            // not blank out the history.
            self::USER => User::query()->whereIn('id', $ids)->get(['id', 'name', 'email']),
            self::STUDENT => Student::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereIn('id', $ids)
                ->get(['id', 'college_id', 'student_number', 'first_name', 'middle_name', 'last_name', 'deleted_at']),
            self::STAFF => Faculty::withoutGlobalScopes()
                ->where('college_id', $collegeId)
                ->whereIn('id', $ids)
                ->get(['id', 'college_id', 'employee_code', 'first_name', 'middle_name', 'last_name', 'deleted_at']),
            default => collect(),
        };
    }
}
