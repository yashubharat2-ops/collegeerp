<?php

namespace App\Domain\Student\Services;

use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentEnrollment;
use App\Models\StudentQualification;
use Illuminate\Support\Collection;

/**
 * Everything the Student Profile renders, assembled ONCE from the records the
 * Registration Wizard writes.
 *
 * Why a service: the profile is the place where the whole lifecycle is read back
 * (draft → submission → approval → student → initial enrollment → later
 * enrollments → qualifications → documents). If each Blade section resolved its
 * own academic context, the header, the summary cards and the Academic
 * Information panel could disagree about which enrollment is current, or print a
 * semester label one way in the header and another in the table.
 *
 * What it does NOT do: it never writes, never invents a value, and never exposes
 * protected identity data. Sensitive numbers (Aadhaar / government ID) are
 * reachable only through the masking helpers on the model, and documents are
 * exposed as route-based references — never as storage paths.
 */
class StudentProfileService
{
    public function __construct(private readonly AcademicContextService $academic) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Student $student): array
    {
        $current = $student->currentEnrollment();
        $previous = $student->previousEnrollments();

        $qualifications = $this->qualifications($student);
        $documents = $this->documents($student);

        return [
            'currentEnrollment' => $current,
            'previousEnrollments' => $previous,
            'enrollmentHistory' => $this->history($student, $current),
            'academic' => $this->academicContext($student, $current),
            'summary' => $this->summary($student, $current),
            'qualifications' => $qualifications,
            'qualificationStats' => $this->qualificationStats($qualifications),
            'documents' => $documents,
            'documentStats' => $this->documentStats($documents),
            'admissionNumber' => $this->admissionNumber($student),
            'photo' => $this->photo($student),
            'addresses' => [
                'permanent' => $this->address([
                    'address_line_1' => $student->address_line_1,
                    'address_line_2' => $student->address_line_2,
                    'city' => $student->city,
                    'district' => $student->district,
                    'state' => $student->state,
                    'postal_code' => $student->postal_code,
                    'country' => $student->country,
                ]),
                'correspondence' => $this->address($student->correspondenceAddress()),
                'sameAsPermanent' => $student->correspondence_same_as_permanent !== false,
            ],
        ];
    }

    /**
     * The admission number of a student who came through the Admissions module.
     *
     * A direct registration does not create an admission (registration approval
     * IS the confirmed admission for that route — see
     * docs/student_management_foundation_design.md §11), so this is null for
     * those students and the profile says so instead of inventing a number.
     */
    public function admissionNumber(Student $student): ?string
    {
        $number = $student->admissionApplication?->admission?->admission_number;

        return is_string($number) && trim($number) !== '' ? trim($number) : null;
    }

    /**
     * The photograph reference the header shows.
     *
     * The file itself stays on the private disk: only a route name and the fact
     * that a photo EXISTS are returned, so the view can render an <img> pointing
     * at the authorized streaming action (or the initials fallback, which needs
     * no request at all).
     *
     * @return array{available: bool, initials: string, alt: string}
     */
    public function photo(Student $student): array
    {
        $first = trim((string) $student->first_name);
        $last = trim((string) $student->last_name);

        $initials = mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, 1));

        if ($initials === '') {
            $initials = mb_strtoupper(mb_substr((string) $student->student_number, 0, 2));
        }

        return [
            'available' => filled($student->photo_path),
            'initials' => $initials !== '' ? $initials : 'ST',
            'alt' => 'Photograph of '.$student->fullName(),
        ];
    }

    /**
     * The header/summary facts. Each entry is [label, value, state] where state
     * is one of ok / pending / muted — the view decides the badge colour, not
     * the data.
     *
     * @return array<int, array{label: string, value: string, state: string}>
     */
    public function summary(Student $student, ?StudentEnrollment $current): array
    {
        $registrationState = match (true) {
            $student->isRegistrationApproved() => 'ok',
            $student->isRegistrationPending() => 'pending',
            $student->isRegistrationRejected() => 'danger',
            default => 'muted',
        };

        $enrollmentState = match (true) {
            $current !== null => 'ok',
            $student->enrollments->contains(fn (StudentEnrollment $e) => $e->isPendingApproval()) => 'pending',
            default => 'muted',
        };

        return [
            ['label' => 'Registration', 'value' => $student->registrationStatusLabel(), 'state' => $registrationState],
            [
                'label' => 'Enrollment status',
                'value' => $current !== null
                    ? $current->statusLabel()
                    : ($enrollmentState === 'pending' ? 'Pending approval' : 'No active enrollment'),
                'state' => $enrollmentState,
            ],
            ['label' => 'Academic year', 'value' => $current?->academicYear?->name ?? 'Not set', 'state' => $current ? 'ok' : 'muted'],
            ['label' => 'Programme / course', 'value' => $current?->program?->name ?? 'Not set', 'state' => $current ? 'ok' : 'muted'],
            ['label' => 'Semester / year of study', 'value' => $current?->semesterLabel() ?? 'Not set', 'state' => $current ? 'ok' : 'muted'],
            ['label' => 'Section / batch', 'value' => $current?->section?->name ?? 'Not assigned', 'state' => $current?->section ? 'ok' : 'muted'],
        ];
    }

    /**
     * The academic detail of the CURRENT enrollment, plus who owns each fact.
     *
     * @return array<string, mixed>
     */
    public function academicContext(Student $student, ?StudentEnrollment $current): array
    {
        $admissionApplication = $student->admissionApplication;

        return [
            'academicYear' => $current?->academicYear,
            'program' => $current?->program,
            'term' => $current?->academicTerm,
            'semesterLabel' => $current?->semesterLabel(),
            'section' => $current?->section,
            'enrollmentNumber' => $current?->enrollment_number,
            'rollNumber' => $current?->roll_number,
            'enrollmentDate' => $current?->enrollment_date,
            'admissionDate' => $student->admission_date,
            'admissionCategory' => $student->category,
            'admissionCategoryLabel' => Student::categoryLabel($student->category),
            'registrationStatus' => $student->registrationStatusLabel(),
            'enrollmentStatus' => $current?->statusLabel(),
            'admissionApplication' => $admissionApplication?->application_number,
            'admissionNumber' => $this->admissionNumber($student),
        ];
    }

    /**
     * Every enrollment of the student, newest academic year first, flagged as
     * current or historical. The current row is never determined by insertion
     * order (that is Student::currentEnrollment()'s job).
     *
     * @return Collection<int, array{enrollment: StudentEnrollment, isCurrent: bool}>
     */
    public function history(Student $student, ?StudentEnrollment $current): Collection
    {
        return $student->enrollments
            ->sortByDesc(fn (StudentEnrollment $enrollment) => sprintf(
                '%s-%011d',
                $enrollment->academicYear?->starts_on?->format('Ymd') ?? '00000000',
                $enrollment->id,
            ))
            ->values()
            ->map(fn (StudentEnrollment $enrollment) => [
                'enrollment' => $enrollment,
                'isCurrent' => $current !== null && (int) $current->id === (int) $enrollment->id,
            ]);
    }

    /**
     * Saved qualification rows, each with the percentage it may legitimately
     * show.
     *
     * A percentage is derived ONLY when the row is marks-based and the pair is
     * coherent (0 < max, obtained <= max). A grade-based result keeps its grade
     * and shows no percentage, and an invalid pair shows none either — the
     * profile must never print a number the board did not award.
     *
     * @return Collection<int, array{qualification: StudentQualification, percentage: ?float, subjects: array<int, array<string, mixed>>}>
     */
    public function qualifications(Student $student): Collection
    {
        return $student->qualifications
            ->map(function (StudentQualification $qualification): array {
                $graded = filled($qualification->grade) || filled($qualification->grade_letter);

                $derived = null;

                if (! $graded) {
                    $obtained = $qualification->marks_obtained;
                    $max = $qualification->max_marks;

                    if (is_numeric($obtained) && is_numeric($max) && (float) $max > 0 && (float) $obtained <= (float) $max) {
                        $derived = round(((float) $obtained / (float) $max) * 100, 2);
                    }
                }

                $stored = is_numeric($qualification->percentage) ? (float) $qualification->percentage : null;

                $subjects = $qualification->subjects;
                $subjects = is_array($subjects) ? array_values(array_filter($subjects, 'is_array')) : [];

                return [
                    'qualification' => $qualification,
                    'percentage' => $derived ?? $stored,
                    'subjects' => $subjects,
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array{qualification: StudentQualification, percentage: ?float, subjects: array<int, array<string, mixed>>}>  $qualifications
     * @return array{count: int, levels: array<int, string>}
     */
    public function qualificationStats(Collection $qualifications): array
    {
        return [
            'count' => $qualifications->count(),
            'levels' => $qualifications
                ->map(fn (array $row) => $row['qualification']->levelLabel())
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * Documents grouped for the gallery, with the type of preview that is safe:
     * an image can be streamed inline through the authorized preview action, any
     * other file is represented by its file-type badge and a download link.
     *
     * @return Collection<int, array{document: StudentDocument, preview: string, extension: string}>
     */
    public function documents(Student $student): Collection
    {
        return $student->documents
            ->sortByDesc(fn (StudentDocument $document) => sprintf('%011d', $document->id))
            ->values()
            ->map(function (StudentDocument $document): array {
                $extension = strtolower((string) pathinfo((string) $document->original_filename, PATHINFO_EXTENSION));
                $mime = strtolower((string) $document->mime_type);

                $isImage = str_starts_with($mime, 'image/')
                    || in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);

                $preview = match (true) {
                    $isImage => 'image',
                    $extension === 'pdf' || $mime === 'application/pdf' => 'pdf',
                    default => 'file',
                };

                return [
                    'document' => $document,
                    'preview' => $preview,
                    'extension' => $extension !== '' ? $extension : 'file',
                ];
            });
    }

    /**
     * @param  Collection<int, array{document: StudentDocument, preview: string, extension: string}>  $documents
     * @return array{total: int, verified: int, pending: int, rejected: int}
     */
    public function documentStats(Collection $documents): array
    {
        $statuses = $documents->countBy(fn (array $row) => (string) $row['document']->verification_status);

        return [
            'total' => $documents->count(),
            'verified' => (int) ($statuses['verified'] ?? 0),
            'pending' => (int) ($statuses['pending'] ?? 0),
            'rejected' => (int) ($statuses['rejected'] ?? 0),
        ];
    }

    /**
     * One postal address, already joined for display. Null when nothing is set,
     * so the view can show a single placeholder instead of a row of dashes.
     *
     * @param  array<string, string|null>  $parts
     */
    private function address(array $parts): ?string
    {
        $lines = array_values(array_filter(array_map(
            fn ($value) => is_string($value) ? trim($value) : '',
            $parts,
        ), fn (string $value) => $value !== ''));

        return $lines === [] ? null : implode(', ', $lines);
    }
}
