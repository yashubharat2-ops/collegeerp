<?php

namespace App\Domain\Student\Services;

use App\Models\AdmissionDocumentType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The existing college defaults, with a course-specific pivot override. */
final class RegistrationDocumentRequirements
{
    /** @return Collection<int, array{id: int, name: string, code: string, required: bool, programs: array<int, bool>}> */
    public function types(int $collegeId): Collection
    {
        $overrides = DB::table('admission_document_type_program as pivot')
            ->join('programs', function ($join) use ($collegeId): void {
                $join->on('programs.id', '=', 'pivot.program_id')
                    ->where('programs.college_id', $collegeId)->whereNull('programs.deleted_at');
            })
            ->where('pivot.college_id', $collegeId)
            ->get(['pivot.document_type_id', 'pivot.program_id', 'pivot.is_required'])
            ->groupBy('document_type_id');

        return AdmissionDocumentType::query()
            ->where('college_id', $collegeId)
            ->where('status', 'active')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'is_required'])
            ->map(function (AdmissionDocumentType $type) use ($overrides): array {
                $programs = [];
                foreach ($overrides->get($type->id, collect()) as $pivot) {
                    $programs[(int) $pivot->program_id] = (bool) $pivot->is_required;
                }

                return [
                    'id' => (int) $type->id,
                    'name' => $type->name,
                    'code' => $type->code,
                    'required' => (bool) $type->is_required,
                    'programs' => $programs,
                ];
            });
    }

    /** @return Collection<int, array{id: int, name: string, code: string, required: bool, programs: array<int, bool>}> */
    public function missing(int $collegeId, ?int $programId, array $selected): Collection
    {
        $selected = array_map('intval', array_filter($selected, fn ($id): bool => is_int($id) || (is_string($id) && ctype_digit($id))));

        return $this->types($collegeId)->filter(function (array $type) use ($programId, $selected): bool {
            $required = $programId !== null && array_key_exists($programId, $type['programs'])
                ? $type['programs'][$programId]
                : $type['required'];

            return $required && ! in_array($type['id'], $selected, true);
        })->values();
    }

    /** Save names alongside IDs so the approval record remains understandable. */
    public function snapshot(int $collegeId, array $selected): array
    {
        $selected = array_map('intval', $selected);

        return $this->types($collegeId)
            ->filter(fn (array $type): bool => in_array($type['id'], $selected, true))
            ->map(fn (array $type): array => ['id' => $type['id'], 'name' => $type['name'], 'code' => $type['code']])
            ->values()
            ->all();
    }
}
