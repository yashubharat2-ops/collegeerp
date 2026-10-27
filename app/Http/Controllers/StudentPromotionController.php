<?php

namespace App\Http\Controllers;

use App\Domain\Student\Actions\PromoteStudent;
use App\Http\Requests\StudentPromotion\StoreStudentPromotionRequest;
use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Program;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentPromotion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Student promotion.
 *
 * Two-phase so the decision is reviewable and bulk-approvable later:
 * 1. `store`   records a validated, pending promotion request (nothing moves),
 * 2. `approve` executes it transactionally — new enrollment created, source
 *    enrollment marked completed (never deleted), decision audited.
 *
 * No progression rule lives in this controller or the action: the target
 * academic year / program / term / section are operator choices.
 */
class StudentPromotionController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', StudentPromotion::class);

        $query = StudentPromotion::query()
            ->with(['student', 'sourceEnrollment', 'sourceAcademicYear', 'targetAcademicYear', 'targetProgram', 'targetSection', 'targetEnrollment'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($studentId = $request->input('student_id')) {
            $query->where('student_id', $studentId);
        }

        if (in_array($request->input('status'), StudentPromotion::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        if ($yearId = $request->input('target_academic_year_id')) {
            $query->where('target_academic_year_id', $yearId);
        }

        return view('student_promotions.index', [
            'promotions' => $query->paginate(15)->withQueryString(),
            'student_id' => $request->input('student_id'),
            'status' => $request->input('status'),
            'target_academic_year_id' => $request->input('target_academic_year_id'),
            'students' => $this->studentOptions(),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', StudentPromotion::class);

        return view('student_promotions.create', [
            // Eligibility-filtered: the form must not offer a withdrawn student
            // or a withdrawn source enrollment that the action would refuse.
            'students' => $this->promotableStudentOptions(),
            'enrollments' => $this->promotableEnrollmentOptions(),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'code']),
            'academicTerms' => AcademicTerm::query()->with('academicYear:id,name')->orderBy('sequence')->get(),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name', 'code']),
            'sections' => $this->sectionOptions(),
            'selectedStudentId' => $request->input('student_id'),
        ]);
    }

    public function store(StoreStudentPromotionRequest $request, PromoteStudent $action): RedirectResponse
    {
        $collegeId = app(TenantContext::class)->id();

        $promotion = $action->request($request->validated(), $collegeId, auth()->id());

        return redirect()->route('student-promotions.index')
            ->with('success', 'Promotion request recorded for '.$promotion->student?->student_number.'. Approve it to create the new enrollment.');
    }

    public function approve(string $student_promotion, PromoteStudent $action): RedirectResponse
    {
        $model = $this->findScoped($student_promotion);
        $this->authorize('approve', $model);

        $collegeId = app(TenantContext::class)->id();

        $approved = $action->approve($model, $collegeId, auth()->id());

        return redirect()->route('student-promotions.index')->with('success', 'Promotion approved. New enrollment '
            .($approved->targetEnrollment?->enrollment_number ?? '#'.$approved->target_enrollment_id).' created; the previous enrollment is preserved.');
    }

    public function cancel(string $student_promotion, PromoteStudent $action): RedirectResponse
    {
        $model = $this->findScoped($student_promotion);
        $this->authorize('approve', $model);

        $collegeId = app(TenantContext::class)->id();

        $action->cancel($model, $collegeId, auth()->id());

        return redirect()->route('student-promotions.index')->with('success', 'Promotion request cancelled.');
    }

    private function findScoped(string $id): StudentPromotion
    {
        return StudentPromotion::query()->findOrFail($id);
    }

    /**
     * Every student, for the INDEX filter.
     *
     * Deliberately unfiltered: the index searches promotions that already
     * exist, and a promotion may legitimately belong to a student who has
     * since withdrawn. Removing withdrawn students here would make their
     * existing promotions unsearchable.
     */
    private function studentOptions()
    {
        return Student::query()
            ->approvedForOperations()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'student_number', 'first_name', 'last_name']);
    }

    /**
     * Only students the promotion action would accept, for the CREATE form.
     *
     * This mirrors `PromoteStudent::assertPromotable()` and nothing more: that
     * guard rejects exactly a `withdrawn` student, a `withdrawn` source
     * enrollment and a student with an issued transfer certificate. So only
     * `withdrawn` is excluded here.
     *
     * Whether `graduated`, `inactive` or `suspended` students should be
     * promotable is an open institutional decision and is NOT decided by this
     * filter — they remain selectable, exactly as the guard allows.
     *
     * Filtering the dropdown is a convenience, never the enforcement: the
     * action re-checks the same rule server-side on both request and approval,
     * so posting an id that is not listed here is still refused.
     *
     * A student with an issued TC cannot be identified from the students table
     * alone, so it is not filtered here; the action refuses it with a clear
     * message instead.
     */
    private function promotableStudentOptions()
    {
        return Student::query()
            ->approvedForOperations()
            ->where('status', '!=', 'withdrawn')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'student_number', 'first_name', 'last_name']);
    }

    /**
     * Only enrollments usable as a promotion source, for the CREATE form.
     *
     * `PromoteStudent::assertPromotable()` rejects a `withdrawn` source
     * enrollment and no other status, so `withdrawn` is the only value
     * excluded. A `completed` or `cancelled` source stays selectable because
     * the action accepts it (re-admission); whether it *should* is an open
     * decision, not something this filter settles.
     *
     * Tenant scoping is unchanged: `StudentEnrollment::query()` carries the
     * CollegeScope, so this still returns only the active college's rows.
     */
    private function promotableEnrollmentOptions()
    {
        return StudentEnrollment::query()
            ->where('status', '!=', 'withdrawn')
            ->with(['student:id,student_number', 'academicYear:id,name', 'program:id,name', 'section:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    private function sectionOptions()
    {
        return Section::query()
            ->with(['academicYear:id,name', 'program:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'academic_year_id', 'program_id']);
    }
}
