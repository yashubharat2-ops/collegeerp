<?php

namespace App\Http\Controllers;

use App\Domain\Student\Services\StudentIdCardService;
use App\Models\AcademicYear;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Services\Audit\AuditLogService;
use App\Support\Listing\ListSelection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Student ID cards.
 *
 * An ID card is a GENERATED VIEW of existing Student + StudentEnrollment data,
 * not a second identity record: nothing is persisted, no card number is minted,
 * and there is no id-card table. That is deliberate — a duplicate identity
 * master would drift from the student record it is supposed to represent.
 * Card contents therefore come from one place
 * (App\Domain\Student\Services\StudentIdCardService), shared by the single-card
 * screen and the batch generation below.
 *
 * No QR/barcode library is bundled in this project, so the card exposes the
 * verification payload as text (and as a data attribute) instead of pulling in
 * a heavy dependency. If a QR library is added later it renders from
 * `StudentIdCardService::verificationPayload()` unchanged.
 *
 * There is no model for an ID card, so authorization uses the project's RBAC
 * primitive directly (User::hasPermission, tenant-scoped) plus the Student
 * policy for the record itself — on the single card AND per record on a batch.
 */
class StudentIdCardController extends Controller
{
    /**
     * Upper bound on one printable batch. The listing page can select at most
     * one page of rows (200 is the widest supported page), so this is head-room
     * against a hand-crafted request rather than a limit a real click can hit.
     */
    public const BATCH_LIMIT = ListSelection::DEFAULT_LIMIT;

    public function __construct(private readonly StudentIdCardService $cards) {}

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

        $college = app(TenantContext::class)->college();
        $card = $this->cards->cardFor($model, $college);

        $audit->record('student_id_card.generated', $model, [], $this->cards->auditContext($model, $card['enrollment']));

        return view('student_id_cards.show', [
            'student' => $model,
            'enrollment' => $card['enrollment'],
            'campus' => $card['campus'],
            'validUntil' => $card['validUntil'],
            'payload' => $card['payload'],
            'college' => $college,
        ]);
    }

    /**
     * Batch ID cards for an authorized selection — the target of the listing's
     * bulk "Generate ID cards" action.
     *
     * The ids arrive as a request to RE-QUERY, never as a set of records to
     * trust: they are shape-checked (ListSelection), re-resolved through the
     * tenant-scoped Student query (so a foreign college's id simply does not
     * exist here) and re-authorized one record at a time through the Student
     * policy. A student who cannot be authorized is skipped; if none survives,
     * the whole request is forbidden.
     *
     * Nothing is stored: one audit entry per generated card is the only write,
     * exactly as on the single-card screen.
     */
    public function batch(Request $request, AuditLogService $audit): View
    {
        $this->requirePermission('student_id_cards.generate');

        $ids = ListSelection::ids($request->input('ids', []), self::BATCH_LIMIT);

        abort_if($ids === [], 404, 'Select at least one student to generate ID cards for.');

        $students = Student::query()
            ->with(['enrollments.academicYear', 'enrollments.program', 'enrollments.section.campus'])
            ->whereIn('id', $ids)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id')
            ->get()
            ->filter(fn (Student $student) => $request->user()?->can('view', $student) ?? false)
            ->values();

        abort_if($students->isEmpty(), 403, 'None of the selected students could be authorized for this operation.');

        $college = app(TenantContext::class)->college();
        $cards = $this->cards->cardsFor($students, $college);

        $this->auditCards($students, $cards, $audit);

        return view('student_id_cards.batch', [
            'cards' => $cards,
            'college' => $college,
            // How many ids were asked for, so the page can say honestly that a
            // cross-tenant/unviewable selection was skipped instead of printed.
            'requestedCount' => count($ids),
        ]);
    }

    /**
     * One audit entry per generated card, matching the single-card action name.
     *
     * @param Collection<int, Student> $students
     * @param array<int, array<string, mixed>> $cards
     */
    private function auditCards(Collection $students, array $cards, AuditLogService $audit): void
    {
        foreach ($students as $index => $student) {
            $audit->record(
                'student_id_card.generated',
                $student,
                [],
                $this->cards->auditContext($student, $cards[$index]['enrollment'] ?? null)
            );
        }
    }

    private function requirePermission(string $permission): void
    {
        abort_unless(auth()->user()?->hasPermission($permission), 403);
    }
}
