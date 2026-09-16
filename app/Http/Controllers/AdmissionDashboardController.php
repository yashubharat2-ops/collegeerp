<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\AdmissionDocument;
use App\Models\AdmissionEnquiry;
use App\Models\Program;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdmissionDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        // Allow admission_dashboard.view or admission_applications.view for backward compatibility
        $user = $request->user();
        if (! $user->hasPermission('admission_dashboard.view') && ! $user->hasPermission('admission_applications.view') && ! $user->hasPermission('admission_reports.view')) {
            abort(403);
        }

        // Tenant scoped via global scopes
        $totalEnquiries = AdmissionEnquiry::query()->count();
        $totalApplicants = AdmissionApplicant::query()->count();
        $totalApplications = AdmissionApplication::query()->count();

        $draft = AdmissionApplication::query()->where('status', 'draft')->count();
        $submitted = AdmissionApplication::query()->where('status', 'submitted')->count();
        $underReview = AdmissionApplication::query()->where('status', 'under_review')->count();
        $approved = AdmissionApplication::query()->where('status', 'approved')->count();
        $rejected = AdmissionApplication::query()->where('status', 'rejected')->count();
        $admitted = AdmissionApplication::query()->where('status', 'admitted')->count();
        $cancelled = AdmissionApplication::query()->where('status', 'cancelled')->count();

        $pendingDocs = AdmissionDocument::query()->where('verification_status', 'pending')->count();
        $verifiedDocs = AdmissionDocument::query()->where('verification_status', 'verified')->count();
        $rejectedDocs = AdmissionDocument::query()->where('verification_status', 'rejected')->count();
        $totalDocs = AdmissionDocument::query()->count();

        $totalAdmissions = Admission::query()->count();

        // Program-wise counts
        $programWise = AdmissionApplication::query()
            ->selectRaw('program_id, COUNT(*) as count')
            ->groupBy('program_id')
            ->with('program:id,name,code')
            ->orderBy('count', 'desc')
            ->get();

        // Academic year wise
        $yearWise = AdmissionApplication::query()
            ->selectRaw('academic_year_id, COUNT(*) as count')
            ->groupBy('academic_year_id')
            ->with('academicYear:id,name,code')
            ->orderBy('count', 'desc')
            ->get();

        // Status breakdown for chart/table
        $statusBreakdown = [
            'draft' => $draft,
            'submitted' => $submitted,
            'under_review' => $underReview,
            'approved' => $approved,
            'rejected' => $rejected,
            'admitted' => $admitted,
            'cancelled' => $cancelled,
        ];

        return view('admission_dashboard.index', [
            'totalEnquiries' => $totalEnquiries,
            'totalApplicants' => $totalApplicants,
            'totalApplications' => $totalApplications,
            'totalAdmissions' => $totalAdmissions,
            'draft' => $draft,
            'submitted' => $submitted,
            'underReview' => $underReview,
            'approved' => $approved,
            'rejected' => $rejected,
            'admitted' => $admitted,
            'cancelled' => $cancelled,
            'pendingDocs' => $pendingDocs,
            'verifiedDocs' => $verifiedDocs,
            'rejectedDocs' => $rejectedDocs,
            'totalDocs' => $totalDocs,
            'programWise' => $programWise,
            'yearWise' => $yearWise,
            'statusBreakdown' => $statusBreakdown,
        ]);
    }
}
