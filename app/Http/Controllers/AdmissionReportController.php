<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Admission;
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

        $academicYearId = $request->input('academic_year_id');
        $programId = $request->input('program_id');
        $status = $request->input('status');

        // Application status report
        $appQuery = AdmissionApplication::query();
        if ($academicYearId) {
            $appQuery->where('academic_year_id', $academicYearId);
        }
        if ($programId) {
            $appQuery->where('program_id', $programId);
        }
        if ($status && in_array($status, ['draft','submitted','under_review','approved','rejected','cancelled','admitted'], true)) {
            $appQuery->where('status', $status);
        }

        $applications = (clone $appQuery)
            ->with(['applicant','program','academicYear'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(20, ['*'], 'applications_page')
            ->withQueryString();

        $statusCounts = (clone $appQuery)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count','status');

        $programWise = AdmissionApplication::query()
            ->when($academicYearId, fn($q) => $q->where('academic_year_id', $academicYearId))
            ->selectRaw('program_id, COUNT(*) as total, SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) as approved, SUM(CASE WHEN status = "admitted" THEN 1 ELSE 0 END) as admitted')
            ->groupBy('program_id')
            ->with('program:id,name,code')
            ->orderByDesc('total')
            ->get();

        $yearWise = AdmissionApplication::query()
            ->when($programId, fn($q) => $q->where('program_id', $programId))
            ->selectRaw('academic_year_id, COUNT(*) as total, SUM(CASE WHEN status = "approved" THEN 1 ELSE 0 END) as approved, SUM(CASE WHEN status = "admitted" THEN 1 ELSE 0 END) as admitted')
            ->groupBy('academic_year_id')
            ->with('academicYear:id,name,code')
            ->orderByDesc('total')
            ->get();

        // Document verification report
        $docQuery = AdmissionDocument::query()
            ->when($academicYearId, function ($q) use ($academicYearId) {
                $q->whereHas('application', fn($aq) => $aq->where('academic_year_id', $academicYearId));
            })
            ->when($programId, function ($q) use ($programId) {
                $q->whereHas('application', fn($aq) => $aq->where('program_id', $programId));
            });

        $docStatusCounts = (clone $docQuery)
            ->selectRaw('verification_status, COUNT(*) as count')
            ->groupBy('verification_status')
            ->pluck('count','verification_status');

        // Merit/selection report
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

        // Admissions report
        $admissionQuery = Admission::query()
            ->when($academicYearId, fn($q) => $q->where('academic_year_id', $academicYearId))
            ->when($programId, fn($q) => $q->where('program_id', $programId));

        $admissionStatusCounts = (clone $admissionQuery)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count','status');

        $admissions = (clone $admissionQuery)
            ->with(['applicant','program','academicYear','application'])
            ->orderByDesc('admission_date')
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'admissions_page')
            ->withQueryString();

        return view('admission_reports.index', [
            'applications' => $applications,
            'statusCounts' => $statusCounts,
            'programWise' => $programWise,
            'yearWise' => $yearWise,
            'docStatusCounts' => $docStatusCounts,
            'selectionCounts' => $selectionCounts,
            'admissionStatusCounts' => $admissionStatusCounts,
            'admissions' => $admissions,
            'academicYears' => $this->academicYearOptions(),
            'programs' => $this->programOptions(),
            'academic_year_id' => $academicYearId,
            'program_id' => $programId,
            'status' => $status,
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
