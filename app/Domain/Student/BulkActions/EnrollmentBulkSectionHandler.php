<?php

namespace App\Domain\Student\BulkActions;

use App\Domain\Student\Services\StudentService;
use App\Models\College;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Support\BulkAction\BulkActionHandler;
use App\Support\BulkAction\BulkActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Bulk section assignment for enrollments.
 *
 * Rules:
 * - The target section is re-resolved inside the active college (a foreign or
 *   missing id is rejected, never trusted from the browser).
 * - Every selected enrollment must already belong to that section's academic year
 *   AND program. Bulk section assignment never moves an enrollment to another year
 *   or program. If any selected enrollment does not match, NOTHING is changed.
 * - Each change goes through {@see StudentService::updateEnrollment()}, which
 *   locks the owning student, re-reads the enrollment, and re-checks the section
 *   year and program. That keeps the rules and audit on the single-record path.
 * - All-or-nothing: the whole selection runs in one transaction, so any failure
 *   rolls every change back.
 */
class EnrollmentBulkSectionHandler extends BulkActionHandler
{
    public function __construct(private readonly StudentService $students) {}

    public function modelClass(): string
    {
        return StudentEnrollment::class;
    }

    public function requiredPermission(): ?string
    {
        return 'student_enrollments.update';
    }

    public function policyAbility(): ?string
    {
        return 'update';
    }

    public function handle(Collection $records, User $user, College $college, array $parameters = []): BulkActionResult
    {
        $rawSectionId = $parameters['section_id'] ?? null;

        if (! is_scalar($rawSectionId) || ! ctype_digit((string) $rawSectionId) || (int) $rawSectionId < 1) {
            return BulkActionResult::failed('Choose a valid section.');
        }

        // Tenant-scoped re-resolution of the target section.
        $section = Section::withoutGlobalScopes()
            ->where('college_id', $college->id)
            ->whereNull('deleted_at')
            ->whereKey((int) $rawSectionId)
            ->first();

        if (! $section) {
            return BulkActionResult::failed('Choose a valid section.');
        }

        // Pre-check every selected enrollment before any write: the same year and
        // program as the section, and not already in that section (a no-op).
        $mismatched = $records->filter(fn (StudentEnrollment $e): bool => (int) $e->academic_year_id !== (int) $section->academic_year_id
            || (int) $e->program_id !== (int) $section->program_id
        )->count();

        if ($mismatched > 0) {
            return BulkActionResult::failed(
                $mismatched === 1
                    ? '1 selected enrollment belongs to a different academic year or program than this section. Nothing was changed.'
                    : $mismatched.' selected enrollments belong to a different academic year or program than this section. Nothing was changed.'
            );
        }

        $changed = 0;

        try {
            DB::transaction(function () use ($records, $college, $section, &$changed): void {
                // Deterministic lock order: each update locks its owning student.
                foreach ($records->sortBy(['student_id', 'id']) as $enrollment) {
                    if ((int) $enrollment->section_id === (int) $section->id) {
                        continue;
                    }

                    $this->students->updateEnrollment($enrollment, [
                        'section_id' => $section->id,
                    ], (int) $college->id);

                    $changed++;
                }
            });
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first()
                ?: 'The section could not be assigned. No records were changed.';

            return BulkActionResult::failed($message.' Nothing was changed.');
        } catch (Throwable) {
            return BulkActionResult::failed('The section could not be assigned. No records were changed.');
        }

        if ($changed === 0) {
            return BulkActionResult::success('The selected enrollments are already in that section.', 0);
        }

        return BulkActionResult::success(
            $changed === 1 ? '1 enrollment assigned to '.$section->name.'.' : $changed.' enrollments assigned to '.$section->name.'.',
            $changed
        );
    }
}
