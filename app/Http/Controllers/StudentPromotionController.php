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
            'students' => $this->studentOptions(),
            'enrollments' => $this->enrollmentOptions(),
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

    private function studentOptions()
    {
        return Student::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'student_number', 'first_name', 'last_name']);
    }

    private function enrollmentOptions()
    {
        return StudentEnrollment::query()
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
