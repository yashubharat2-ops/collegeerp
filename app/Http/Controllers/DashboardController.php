<?php

namespace App\Http\Controllers;

use App\Models\College;
use App\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(TenantContext $tenantContext): View
    {
        $user = auth()->user();
        $colleges = $user->isSuperAdmin()
            ? College::query()->where('status', 'active')->orderBy('name')->get()
            : $user->colleges()->where('status', 'active')->orderBy('name')->get();

        // Student records are a verifiable dashboard metric: Student is the
        // officially enrolled person record, soft-deleted rows are excluded by
        // the model, and both the permission and active tenant are required.
        // Explicit college_id filtering supplements Student's global tenant
        // scope and intentionally counts all record statuses, not just active.
        $canViewStudentMetric = $tenantContext->has()
            && $user->hasPermission('students.view');
        $studentRecordCount = $canViewStudentMetric
            ? Student::query()->where('college_id', $tenantContext->id())->count()
            : null;

        return view('dashboard.index', compact('colleges', 'canViewStudentMetric', 'studentRecordCount'));
    }
}
