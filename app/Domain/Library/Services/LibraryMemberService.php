<?php

namespace App\Domain\Library\Services;

use App\Domain\Foundation\Scopes\CollegeScope;
use App\Domain\Library\Support\CollegeRowLock;
use App\Models\College;
use App\Models\LibraryMember;
use App\Models\LibraryTransaction;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LibraryMemberService — membership of an existing student enrollment.
 *
 * The member does not copy the student's name or number. It only records the
 * library relationship, and it refuses a second active membership for the same
 * enrollment. The enrollment and its student must belong to the active college.
 *
 * A membership that has been used in circulation cannot be deleted: the
 * transaction history has to keep a real member to point at.
 */
class LibraryMemberService
{
    private const DUPLICATE_CODE = 'A library member with this code already exists for the active college.';

    private const DUPLICATE_ACTIVE = 'This enrollment already has an active library membership.';

    private const IN_USE = 'This member has circulation history and cannot be deleted.';

    private const ENROLLMENT_INVALID = 'The selected enrollment does not belong to the active college.';

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(College $college, array $data, User $actor): LibraryMember
    {
        return DB::transaction(function () use ($college, $data, $actor): LibraryMember {
            CollegeRowLock::acquire((int) $college->getKey());

            $member = new LibraryMember([
                'college_id' => $college->getKey(),
                'student_enrollment_id' => (int) $data['student_enrollment_id'],
                'member_code' => $data['member_code'],
                'membership_date' => $this->date($data['membership_date']),
                'expiry_date' => $this->dateOrNull($data['expiry_date'] ?? null),
                'status' => $data['status'],
                'remarks' => $this->blankToNull($data['remarks'] ?? null),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->assertEnrollmentBelongsToCollege($member);
            $this->assertDates($member);
            $this->assertUniqueCode($member);
            $this->assertNoOtherActiveMembership($member);

            try {
                $member->save();
            } catch (QueryException $e) {
                throw $this->duplicateFromQueryException($e);
            }

            $this->audit->record('library_members.created', $member, [], $this->snapshot($member));

            return $member->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LibraryMember $member, array $data, User $actor): LibraryMember
    {
        $this->assertTenant($member);

        return DB::transaction(function () use ($member, $data, $actor): LibraryMember {
            CollegeRowLock::acquire((int) $member->college_id);

            $locked = $this->lock($member);
            $old = $this->snapshot($locked);

            if (array_key_exists('student_enrollment_id', $data)
                && (int) $data['student_enrollment_id'] !== (int) $locked->student_enrollment_id) {
                throw ValidationException::withMessages([
                    'student_enrollment_id' => 'The enrollment of an existing membership cannot be changed.',
                ]);
            }

            foreach (['member_code', 'membership_date', 'expiry_date', 'status', 'remarks'] as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $locked->{$field} = match ($field) {
                    'membership_date' => $this->date($data[$field]),
                    'expiry_date' => $this->dateOrNull($data[$field]),
                    'remarks' => $this->blankToNull($data[$field]),
                    default => $data[$field],
                };
            }

            $locked->updated_by = $actor->getKey();

            $this->assertDates($locked);
            $this->assertUniqueCode($locked);
            $this->assertNoOtherActiveMembership($locked);

            try {
                $locked->save();
            } catch (QueryException $e) {
                throw $this->duplicateFromQueryException($e);
            }

            $this->audit->record('library_members.updated', $locked, $old, $this->snapshot($locked));

            return $locked->refresh();
        });
    }

    public function delete(LibraryMember $member, User $actor): void
    {
        $this->assertTenant($member);

        DB::transaction(function () use ($member): void {
            CollegeRowLock::acquire((int) $member->college_id);

            $locked = $this->lock($member);

            $hasHistory = LibraryTransaction::withoutGlobalScope(CollegeScope::class)
                ->where('college_id', $locked->college_id)
                ->where('library_member_id', $locked->getKey())
                ->exists();

            if ($hasHistory) {
                throw ValidationException::withMessages(['member' => self::IN_USE]);
            }

            $snapshot = $this->snapshot($locked);
            $locked->delete();

            $this->audit->record('library_members.deleted', $locked, $snapshot, []);
        });
    }

    private function lock(LibraryMember $member): LibraryMember
    {
        return LibraryMember::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $member->college_id)
            ->whereKey($member->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertEnrollmentBelongsToCollege(LibraryMember $member): void
    {
        $enrollment = StudentEnrollment::withoutGlobalScope(CollegeScope::class)
            ->whereKey($member->student_enrollment_id)
            ->where('college_id', $member->college_id)
            ->whereNull('deleted_at')
            ->first();

        if (! $enrollment) {
            throw ValidationException::withMessages(['student_enrollment_id' => self::ENROLLMENT_INVALID]);
        }

        $studentOk = Student::withoutGlobalScope(CollegeScope::class)
            ->whereKey($enrollment->student_id)
            ->where('college_id', $member->college_id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $studentOk) {
            throw ValidationException::withMessages(['student_enrollment_id' => self::ENROLLMENT_INVALID]);
        }
    }

    private function assertDates(LibraryMember $member): void
    {
        if ($member->expiry_date === null) {
            return;
        }

        $start = Carbon::parse($member->membership_date)->startOfDay();
        $end = Carbon::parse($member->expiry_date)->startOfDay();

        if ($end->lt($start)) {
            throw ValidationException::withMessages([
                'expiry_date' => 'The expiry date must be on or after the membership date.',
            ]);
        }
    }

    private function assertUniqueCode(LibraryMember $member): void
    {
        $duplicate = LibraryMember::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $member->college_id)
            ->where('member_code', $member->member_code)
            ->whereNull('deleted_at')
            ->when($member->exists, fn ($query) => $query->whereKeyNot($member->getKey()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['member_code' => self::DUPLICATE_CODE]);
        }
    }

    private function assertNoOtherActiveMembership(LibraryMember $member): void
    {
        if ($member->status !== LibraryMember::STATUS_ACTIVE) {
            return;
        }

        $duplicate = LibraryMember::withoutGlobalScope(CollegeScope::class)
            ->where('college_id', $member->college_id)
            ->where('student_enrollment_id', $member->student_enrollment_id)
            ->where('status', LibraryMember::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->when($member->exists, fn ($query) => $query->whereKeyNot($member->getKey()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['student_enrollment_id' => self::DUPLICATE_ACTIVE]);
        }
    }

    private function duplicateFromQueryException(QueryException $e): ValidationException
    {
        $message = $e->getMessage();

        if (str_contains($message, 'member_code')) {
            return ValidationException::withMessages(['member_code' => self::DUPLICATE_CODE]);
        }

        if (str_contains($message, 'student_enrollment_id')) {
            return ValidationException::withMessages(['student_enrollment_id' => self::DUPLICATE_ACTIVE]);
        }

        throw $e;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(LibraryMember $member): array
    {
        return [
            'student_enrollment_id' => (int) $member->student_enrollment_id,
            'member_code' => $member->member_code,
            'membership_date' => $member->membership_date?->toDateString(),
            'expiry_date' => $member->expiry_date?->toDateString(),
            'status' => $member->status,
            'remarks' => $member->remarks,
        ];
    }

    private function date(mixed $value): string
    {
        return Carbon::parse($value)->toDateString();
    }

    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }

    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function assertTenant(LibraryMember $member): void
    {
        $collegeId = app(TenantContext::class)->id();

        abort_unless($collegeId !== null && (int) $member->college_id === (int) $collegeId, 403);
    }
}
