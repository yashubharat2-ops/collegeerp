<?php

namespace App\Http\Controllers;

use App\Domain\Admission\Services\AdmissionApplicationWorkflow;
use App\Models\Admission;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdmissionStatusTypeController extends Controller
{
    public function index(Request $request): View
    {
        // Need view permission for admissions or applications
        $user = $request->user();
        if (! $user->hasPermission('admission_applications.view') && ! $user->hasPermission('admissions.view')) {
            abort(403);
        }

        $applicationStatuses = AdmissionApplicationWorkflow::allStatuses();
        $transitions = [];
        foreach ($applicationStatuses as $from) {
            $transitions[$from] = AdmissionApplicationWorkflow::allowedFrom($from);
        }

        $admissionStatuses = Admission::STATUSES;

        return view('admission_status_types.index', [
            'applicationStatuses' => $applicationStatuses,
            'transitions' => $transitions,
            'admissionStatuses' => $admissionStatuses,
        ]);
    }
}
