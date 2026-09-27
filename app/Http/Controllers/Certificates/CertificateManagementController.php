<?php

namespace App\Http\Controllers\Certificates;

use App\Domain\Certificates\CertificateTypes;
use App\Domain\Certificates\Services\CertificateService;
use App\Domain\Certificates\Support\CertificatePdf;
use App\Models\CertificateIssuance;
use App\Models\CertificateRequest;
use App\Models\CertificateTemplate;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentTransfer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class CertificateManagementController extends \App\Http\Controllers\Controller
{
    private function allow(string $permission): void
    {
        abort_unless(auth()->user()?->hasPermission('certificates_'.$permission), 403);
    }

    public function dashboard(): View
    {
        $this->allow('dashboard.view');
        return view('certificates.dashboard', [
            'types' => CertificateTypes::GROUP_1,
            'issued' => CertificateIssuance::query()->count() + StudentTransfer::query()->where('tc_status', 'issued')->count(),
            'pending' => CertificateRequest::query()->where('status', 'pending')->count() + StudentTransfer::query()->where('status', 'pending')->count(),
        ]);
    }

    public function templates(): View
    {
        $this->allow('templates.view');
        return view('certificates.templates', ['templates' => CertificateTemplate::query()->latest()->get(), 'types' => CertificateTypes::GROUP_1]);
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $this->allow('templates.manage');
        $data = $request->validate(['type' => ['required', 'in:tc,bonafide,character'], 'name' => ['required', 'string', 'max:120'], 'body' => ['required', 'string', 'max:20000'], 'is_active' => ['nullable', 'boolean']]);
        CertificateTemplate::create($data + ['college_id' => app(TenantContext::class)->id(), 'created_by' => auth()->id(), 'is_active' => $request->boolean('is_active', true)]);
        return back()->with('success', 'Certificate template saved.');
    }

    public function generation(): View
    {
        $this->allow('generation.view');
        return view('certificates.generate', [
            'types' => array_filter(CertificateTypes::GROUP_1, fn ($type, $key) => $key !== 'tc', ARRAY_FILTER_USE_BOTH),
            'students' => Student::query()->orderBy('first_name')->orderBy('last_name')->get(),
            'templates' => CertificateTemplate::query()->where('is_active', true)->whereIn('type', ['bonafide', 'character'])->get(),
        ]);
    }

    public function generate(Request $request, CertificateService $service): RedirectResponse
    {
        $this->allow('issuance.create');
        $data = $request->validate(['type' => ['required', 'in:bonafide,character'], 'student_id' => ['required', 'integer', 'exists:students,id'], 'enrollment_id' => ['nullable', 'integer'], 'template_id' => ['nullable', 'integer'], 'issued_at' => ['nullable', 'date'], 'purpose' => ['nullable', 'string', 'max:2000']]);
        $issuance = $service->issue($data, app(TenantContext::class)->id(), auth()->id());
        return redirect()->route('certificates.issuance.index')->with('success', "{$issuance->certificate_number} issued.");
    }

    public function issuance(): View
    {
        $this->allow('issuance.view');
        return view('certificates.issuance', [
            'otherCertificates' => CertificateIssuance::query()->with('student')->latest('issued_at')->paginate(15, ['*'], 'other_page'),
            'transferCertificates' => StudentTransfer::query()->with('student')->where('tc_status', 'issued')->latest('tc_issue_date')->paginate(15, ['*'], 'tc_page'),
        ]);
    }

    public function requests(): View
    {
        $this->allow('requests.view');
        return view('certificates.requests', [
            'types' => array_filter(CertificateTypes::GROUP_1, fn ($type, $key) => $key !== 'tc', ARRAY_FILTER_USE_BOTH),
            'students' => Student::query()->orderBy('first_name')->orderBy('last_name')->get(),
            'requests' => CertificateRequest::query()->with('student')->latest()->paginate(15),
            'transferRequests' => StudentTransfer::query()->with('student')->latest()->paginate(15, ['*'], 'tc_page'),
        ]);
    }

    public function storeRequest(Request $request): RedirectResponse
    {
        $this->allow('requests.create');
        $data = $request->validate(['type' => ['required', 'in:bonafide,character'], 'student_id' => ['required', 'integer', 'exists:students,id'], 'enrollment_id' => ['nullable', 'integer'], 'purpose' => ['nullable', 'string', 'max:2000']]);
        $student = Student::query()->findOrFail($data['student_id']);
        if (! empty($data['enrollment_id']) && ! StudentEnrollment::query()->where('student_id', $student->id)->whereKey($data['enrollment_id'])->exists()) {
            return back()->withErrors(['enrollment_id' => 'Choose an enrollment belonging to this student.'])->withInput();
        }
        CertificateRequest::create($data + ['college_id' => app(TenantContext::class)->id(), 'status' => 'pending', 'requested_by' => auth()->id()]);
        return back()->with('success', 'Certificate request submitted.');
    }

    public function reviewRequest(string $certificate_request, Request $request): RedirectResponse
    {
        $this->allow('requests.review');
        $row = CertificateRequest::query()->findOrFail($certificate_request);
        $data = $request->validate(['status' => ['required', 'in:approved,rejected']]);
        $row->update(['status' => $data['status'], 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
        return back()->with('success', 'Certificate request '.$data['status'].'.');
    }

    public function verification(Request $request): View
    {
        $this->allow('verification.view');
        $number = trim((string) $request->query('number'));
        $certificate = null;
        if ($number !== '') {
            $certificate = CertificateIssuance::query()->with('student')->where('certificate_number', $number)->first();
            if (! $certificate) {
                $certificate = StudentTransfer::query()->with('student')->where('tc_number', $number)->where('tc_status', 'issued')->first();
            }
        }
        return view('certificates.verification', compact('number', 'certificate'));
    }

    public function issuancePdf(string $issuance): \Symfony\Component\HttpFoundation\Response
    {
        $this->allow('issuance.view');
        $item = CertificateIssuance::query()->with(['student', 'enrollment.program', 'enrollment.academicYear'])->findOrFail($issuance);
        $student = $item->student;
        $pdf = CertificatePdf::render(CertificateTypes::label($item->type), [
            $item->rendered_content ?? CertificateTypes::label($item->type),
            'Certificate number: '.$item->certificate_number,
            'Student number: '.$student->student_number,
            'Student name: '.$student->fullName(),
            'Program: '.$item->enrollment?->program?->name,
            'Academic year: '.$item->enrollment?->academicYear?->name,
            'Purpose: '.$item->purpose,
            'Date of issue: '.$item->issued_at?->format('d M Y'),
            '', 'Authorized signatory: ______________________________',
        ]);
        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$item->certificate_number.'.pdf"']);
    }

    public function tcPdf(string $student_transfer): \Symfony\Component\HttpFoundation\Response
    {
        $this->allow('issuance.view');
        $tc = StudentTransfer::query()->with(['student', 'enrollment.program', 'enrollment.academicYear', 'certificateTemplate'])->where('tc_status', 'issued')->findOrFail($student_transfer);
        $student = $tc->student;
        $templateContent = $tc->certificateTemplate?->body;
        if ($templateContent) {
            $templateContent = str_replace(['{{student_name}}', '{{student_number}}', '{{college_name}}'], [$student->fullName(), $student->student_number, $student->college?->name ?? 'the institution'], $templateContent);
        }
        $pdf = CertificatePdf::render('Transfer Certificate', [
            $templateContent ?? 'This is to certify that '.$student->fullName().' was a student of the institution.',
            'Certificate number: '.$tc->tc_number,
            'Student number: '.$student->student_number,
            'Student name: '.$student->fullName(),
            'Date of birth: '.$student->date_of_birth?->format('d M Y'),
            'Program: '.$tc->enrollment?->program?->name,
            'Academic year: '.$tc->enrollment?->academicYear?->name,
            'Transfer date: '.$tc->transfer_date?->format('d M Y'),
            'Reason: '.$tc->reason,
            'Destination: '.$tc->destination_institution,
            'Issue date: '.$tc->tc_issue_date?->format('d M Y'),
            '', 'Authorized signatory: ______________________________',
        ]);
        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$tc->tc_number.'.pdf"']);
    }

    public function reports(): View
    {
        $this->allow('reports.view');
        $counts = collect(CertificateTypes::GROUP_1)->mapWithKeys(function ($label, $type) {
            $count = $type === 'tc'
                ? StudentTransfer::query()->where('tc_status', 'issued')->count()
                : CertificateIssuance::query()->where('type', $type)->count();
            return [$label => $count];
        });
        return view('certificates.reports', ['counts' => $counts]);
    }
}
