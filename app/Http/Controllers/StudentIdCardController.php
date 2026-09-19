<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Student ID cards.
 *
 * An ID card is a GENERATED VIEW of existing Student + StudentEnrollment data,
 * not a second identity record: nothing is persisted, no card number is minted,
 * and there is no id-card table. That is deliberate — a duplicate identity
 * master would drift from the student record it is supposed to represent.
 *
 * No QR/barcode library is bundled in this project, so the card exposes the
 * verification payload as text (and as a data attribute) instead of pulling in
 * a heavy dependency. If a QR library is added later it renders from
 * `StudentIdCardController::verificationPayload()` unchanged.
 *
 * There is no model for an ID card, so authorization uses the project's RBAC
 * primitive directly (User::hasPermission, tenant-scoped) plus the Student
 * policy for the record itself.
 */
class StudentIdCardController extends Controller
{
    public function index(Request $request): View
    {
        $this->requirePermission('student_id_cards.view');

        $query = Student::query()
            ->with(['enrollments.academicYear', 'enrollments.program', 'enrollments.section.campus'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('student_number', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($academicYearId = $request->input('academic_year_id')) {
            $query->whereHas('enrollments', fn ($e) => $e->where('academic_year_id', $academicYearId));
        }

        if ($programId = $request->input('program_id')) {
            $query->whereHas('enrollments', fn ($e) => $e->where('program_id', $programId));
        }

        if ($sectionId = $request->input('section_id')) {
            $query->whereHas('enrollments', fn ($e) => $e->where('section_id', $sectionId));
        }

        return view('student_id_cards.index', [
            'students' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'academic_year_id' => $request->input('academic_year_id'),
            'program_id' => $request->input('program_id'),
            'section_id' => $request->input('section_id'),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'sections' => Section::query()->with(['academicYear:id,name', 'program:id,name'])->orderBy('name')->get(['id', 'name', 'code', 'academic_year_id', 'program_id']),
        ]);
    }

    /**
     * Render (and print/download) a student's ID card.
     *
     * Generation is audited because the card is an official artefact; the audit
     * entry is the only thing written.
     */
    public function show(string $student, AuditLogService $audit): View
    {
        $model = Student::query()->findOrFail($student);

        $this->requirePermission('student_id_cards.generate');
        $this->authorize('view', $model);

        $model->load(['enrollments.academicYear', 'enrollments.program', 'enrollments.section.campus']);

        $enrollment = $model->currentEnrollment()
            ?? $model->enrollments
                ->sortByDesc(fn ($e) => sprintf(
                    '%011d%011d',
                    $e->academicYear?->starts_on?->timestamp ?? 0,
                    $e->id
                ))
                ->first();

        $audit->record('student_id_card.generated', $model, [], [
            'id' => $model->id,
            'student_number' => $model->student_number,
            'enrollment_number' => $enrollment?->enrollment_number,
            'academic_year_id' => $enrollment?->academic_year_id,
        ]);

        return view('student_id_cards.show', [
            'student' => $model,
            'enrollment' => $enrollment,
            'college' => app(TenantContext::class)->college(),
            'campus' => $enrollment?->section?->campus,
            'payload' => $this->verificationPayload($model, $enrollment?->enrollment_number),
        ]);
    }

    /**
     * Compact machine-readable payload for the card.
     *
     * Contains only non-sensitive identifiers that a verifier can look up:
     * college code, student number and enrollment number.
     */
    private function verificationPayload(Student $student, ?string $enrollmentNumber): string
    {
        $collegeCode = app(TenantContext::class)->college()?->code ?? 'COLLEGE';

        return strtoupper(implode('|', array_filter([
            $collegeCode,
            $student->student_number,
            $enrollmentNumber,
        ])));
    }

    private function requirePermission(string $permission): void
    {
        abort_unless(auth()->user()?->hasPermission($permission), 403);
    }
}
