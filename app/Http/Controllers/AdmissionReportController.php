<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\AdmissionDocument;
use App\Models\AdmissionMeritEntry;
use App\Models\Program;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdmissionReportController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user->hasPermission('admission_reports.view') && ! $user->hasPermission('admission_applications.view')) {
            abort(403);
        }

        $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'status' => ['nullable', 'in:draft,submitted,under_review,approved,rejected,cancelled,admitted,active'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $academicYearId = $request->input('academic_year_id');
        $programId = $request->input('program_id');
        $status = $request->input('status');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        // Base application query with common filters (tenant scoping via global scope)
        $baseAppQuery = AdmissionApplication::query()
            ->when($academicYearId, fn($q) => $q->where('academic_year_id', $academicYearId))
            ->when($programId, fn($q) => $q->where('program_id', $programId))
            ->when($dateFrom, fn($q) => $q->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->whereDate('created_at', '<=', $dateTo))
            ->when($status && in_array($status, ['draft','submitted','under_review','approved','rejected','cancelled','admitted'], true), fn($q) => $q->where('status', $status));

        // === 1. Admission Summary ===
        $summary = [
            'total_applicants' => AdmissionApplicant::query()
                ->when($dateFrom, fn($q) => $q->whereDate('created_at','>=',$dateFrom))
                ->when($dateTo, fn($q) => $q->whereDate('created_at','<=',$dateTo))
                ->count(),
            'total_applications' => (clone $baseAppQuery)->count(),
            'total_admissions' => Admission::query()
                ->when($academicYearId, fn($q) => $q->where('academic_year_id',$academicYearId))
                ->when($programId, fn($q) => $q->where('program_id',$programId))
                ->when($dateFrom, fn($q) => $q->whereDate('admission_date','>=',$dateFrom))
                ->when($dateTo, fn($q) => $q->whereDate('admission_date','<=',$dateTo))
                ->count(),
            'approved_applications' => (clone $baseAppQuery)->where('status','approved')->count(),
            'rejected_applications' => (clone $baseAppQuery)->where('status','rejected')->count(),
            'pending_applications' => (clone $baseAppQuery)->whereIn('status',['submitted','under_review'])->count(),
        ];

        // === 2. Application Report (paginated) ===
        $applications = (clone $baseAppQuery)
            ->with(['applicant','program','academicYear'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(15, ['*'], 'applications_page')
            ->withQueryString();

        $statusCounts = (clone $baseAppQuery)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count','status');

        // === 3. New Registration Report — recent applicants (acts as registrations) ===
        $newRegistrationQuery = AdmissionApplicant::query()
            ->withCount('applications')
            ->when($dateFrom, fn($q) => $q->whereDate('created_at','>=',$dateFrom))
            ->when($dateTo, fn($q) => $q->whereDate('created_at','<=',$dateTo))
            ->orderByDesc('created_at');

        $newRegistrations = $newRegistrationQuery->paginate(15, ['*'], 'registrations_page')->withQueryString();
        $newRegistrationTotal = AdmissionApplicant::query()
            ->when($dateFrom, fn($q) => $q->whereDate('created_at','>=',$dateFrom))
            ->when($dateTo, fn($q) => $q->whereDate('created_at','<=',$dateTo))
            ->count();

        // === 4. Student Admission Report — admissions (final) ===
        $admissionQuery = Admission::query()
            ->when($academicYearId, fn($q) => $q->where('academic_year_id', $academicYearId))
            ->when($programId, fn($q) => $q->where('program_id', $programId))
            ->when($dateFrom, fn($q) => $q->whereDate('admission_date','>=',$dateFrom))
            ->when($dateTo, fn($q) => $q->whereDate('admission_date','<=',$dateTo))
            ->when($status && in_array($status, Admission::STATUSES, true), fn($q) => $q->where('status', $status));

        $admissionStatusCounts = (clone $admissionQuery)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count','status');

        $admissions = (clone $admissionQuery)
            ->with(['applicant','program','academicYear','application'])
            ->orderByDesc('admission_date')
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'admissions_page')
            ->withQueryString();

        // === 5. Admission Status Report ===
        $admissionStatusReport = [
            'application_status_breakdown' => $statusCounts,
            'admission_status_breakdown' => $admissionStatusCounts,
            'program_wise' => AdmissionApplication::query()
                ->when($academicYearId, fn($q) => $q->where('academic_year_id', $academicYearId))
                ->when($dateFrom, fn($q) => $q->whereDate('created_at','>=',$dateFrom))
                ->when($dateTo, fn($q) => $q->whereDate('created_at','<=',$dateTo))
                ->selectRaw('program_id, COUNT(*) as total, SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) as approved, SUM(CASE WHEN status = "admitted" THEN 1 ELSE 0 END) as admitted, SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) as rejected')
                ->groupBy('program_id')
                ->with('program:id,name,code')
                ->orderByDesc('total')
                ->get(),
            'year_wise' => AdmissionApplication::query()
                ->when($programId, fn($q) => $q->where('program_id', $programId))
                ->when($dateFrom, fn($q) => $q->whereDate('created_at','>=',$dateFrom))
                ->when($dateTo, fn($q) => $q->whereDate('created_at','<=',$dateTo))
                ->selectRaw('academic_year_id, COUNT(*) as total, SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) as approved, SUM(CASE WHEN status = "admitted" THEN 1 ELSE 0 END) as admitted, SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) as rejected')
                ->groupBy('academic_year_id')
                ->with('academicYear:id,name,code')
                ->orderByDesc('total')
                ->get(),
        ];

        // Additional legacy aggregates kept for existing view compatibility
        $programWise = $admissionStatusReport['program_wise'];
        $yearWise = $admissionStatusReport['year_wise'];

        $docQuery = AdmissionDocument::query()
            ->when($academicYearId, function ($q) use ($academicYearId) {
                $q->whereHas('application', fn($aq) => $aq->where('academic_year_id', $academicYearId));
            })
            ->when($programId, function ($q) use ($programId) {
                $q->whereHas('application', fn($aq) => $aq->where('program_id', $programId));
            })
            ->when($dateFrom, fn($q) => $q->whereDate('created_at','>=',$dateFrom))
            ->when($dateTo, fn($q) => $q->whereDate('created_at','<=',$dateTo));

        $docStatusCounts = (clone $docQuery)
            ->selectRaw('verification_status, COUNT(*) as count')
            ->groupBy('verification_status')
            ->pluck('count','verification_status');

        $meritQuery = AdmissionMeritEntry::query()
            ->when($academicYearId, function ($q) use ($academicYearId) {
                $q->whereHas('meritList', fn($mq) => $mq->where('academic_year_id', $academicYearId));
            })
            ->when($programId, function ($q) use ($programId) {
                $q->whereHas('meritList', fn($mq) => $mq->where('program_id', $programId));
            });

        $selectionCounts = (clone $meritQuery)
            ->selectRaw('selection_status, COUNT(*) as count')
            ->groupBy('selection_status')
            ->pluck('count','selection_status');

        return view('admission_reports.index', [
            'summary' => $summary,
            'applications' => $applications,
            'newRegistrations' => $newRegistrations,
            'newRegistrationTotal' => $newRegistrationTotal,
            'admissions' => $admissions,
            'admissionStatusReport' => $admissionStatusReport,
            'statusCounts' => $statusCounts,
            'programWise' => $programWise,
            'yearWise' => $yearWise,
            'docStatusCounts' => $docStatusCounts,
            'selectionCounts' => $selectionCounts,
            'admissionStatusCounts' => $admissionStatusCounts,
            'academicYears' => $this->academicYearOptions(),
            'programs' => $this->programOptions(),
            'academic_year_id' => $academicYearId,
            'program_id' => $programId,
            'status' => $status,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
    }

    private function academicYearOptions()
    {
        return AcademicYear::query()->orderByDesc('starts_on')->get(['id','name','code']);
    }

    private function programOptions()
    {
        return Program::query()->orderBy('name')->get(['id','name','code']);
    }
}
