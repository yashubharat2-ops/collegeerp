<?php

namespace App\Http\Controllers;

use App\Models\{Certificate, CertificateTemplate, CertificateType, StudentEnrollment, StudentTransfer};
use App\Services\Audit\AuditLogService;
use App\Services\Certificates\{CertificateCatalog, CertificateWorkflow};
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificateController extends Controller
{
    private function permit(string $permission): void
    {
        abort_unless(auth()->user()?->hasPermission($permission), 403);
    }

    private function provision(): void
    {
        app(CertificateCatalog::class)->provision(app(TenantContext::class)->require()->id);
    }

    public function index(Request $request)
    {
        $this->permit('certificates.view');
        $this->provision();
        $data = $request->validate(['type' => ['nullable', 'string', 'max:30'], 'stage' => ['nullable', Rule::in(['requests', 'generation', 'issuance', 'verification'])]]);
        $type = isset($data['type']) ? CertificateType::where('code', $data['type'])->firstOrFail() : null;
        $stage = $request->route('certificate_stage') ?? $data['stage'] ?? 'requests';
        $query = Certificate::with(['type', 'student', 'enrollment'])->latest('id');
        if ($type) $query->where('certificate_type_id', $type->id);
        $query->where('status', ['requests' => 'requested', 'generation' => 'requested', 'issuance' => 'generated', 'verification' => 'issued'][$stage]);
        return view('certificates.index', [
            'types' => CertificateType::orderBy('id')->get(), 'type' => $type, 'stage' => $stage,
            'certificates' => $query->paginate(20)->withQueryString(),
            'enrollments' => StudentEnrollment::with(['student', 'program'])->whereHas('student')->orderByDesc('id')->get(),
            'transfers' => StudentTransfer::with('student')->where('status', 'approved')->where('tc_status', '!=', 'cancelled')->get(),
        ]);
    }

    public function store(Request $request, CertificateWorkflow $workflow)
    {
        $this->permit('certificates.request');
        $data = $request->validate([
            'certificate_type_id' => ['required', 'integer'], 'student_enrollment_id' => ['required', 'integer'],
            'student_transfer_id' => ['nullable', 'integer'], 'purpose' => ['nullable', 'string', 'max:2000'],
        ]);
        $certificate = $workflow->request($data);
        return redirect()->route('certificates.show', $certificate)->with('success', 'Certificate requested.');
    }

    /**
     * CSV export of a bulk selection from the certificate register.
     *
     * One register screen covers every certificate type at every stage, so
     * the ticked ids identify the records and the stage stays a view filter.
     * Ids are treated as a request, never as data (normalised, capped,
     * re-queried inside the active college through the model's college scope)
     * and `certificates.view` is re-checked here. The template snapshot, the
     * data snapshot and the free-text purpose are never exported. Requesting,
     * generating, issuing or verifying a certificate stays a single-record
     * workflow.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->permit('certificates.view');

        $ids = ListSelection::ids($request->input('ids', []));

        $certificates = Certificate::query()
            ->with(['type:id,name,code', 'student:id,first_name,middle_name,last_name,student_number', 'enrollment:id,student_id,enrollment_number'])
            ->whereIn('certificates.id', $ids)
            ->orderBy('certificates.id')
            ->get();

        $rows = $certificates->map(fn (Certificate $certificate): array => [
            $certificate->id,
            $certificate->number,
            $certificate->type?->name,
            $certificate->student?->fullName(),
            $certificate->student?->student_number,
            $certificate->enrollment?->enrollment_number,
            $certificate->status,
            $certificate->created_at?->format('Y-m-d H:i'),
            $certificate->generated_at?->format('Y-m-d H:i'),
            $certificate->issued_at?->format('Y-m-d H:i'),
            $certificate->last_verified_at?->format('Y-m-d H:i'),
        ]);

        $audit->record('certificates.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $certificates->count(),
        ]);

        return CsvStreamExport::make('certificates-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders(['Request', 'Number', 'Type', 'Student', 'Student number', 'Enrollment', 'Status', 'Requested at', 'Generated at', 'Issued at', 'Last verified at'])
            ->streamFromCollection($rows);
    }

    /**
     * CSV export of a bulk selection from the Certificate Types screen.
     *
     * Ids are treated as a request, never as data (normalised, capped,
     * re-queried inside the active college through the model's college scope)
     * and `certificate_types.manage` is re-checked here. Managing a type
     * stays on its own screen.
     */
    public function exportTypes(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->permit('certificate_types.manage');

        $ids = ListSelection::ids($request->input('ids', []));

        $types = CertificateType::query()
            ->withCount('templates')
            ->whereIn('certificate_types.id', $ids)
            ->orderBy('certificate_types.id')
            ->get();

        $rows = $types->map(fn (CertificateType $type): array => [
            $type->name,
            $type->code,
            $type->description,
            $type->templates_count,
            $type->builtin_key ? 'Group 1 built-in' : 'College-defined',
        ]);

        $audit->record('certificate_types.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $types->count(),
        ]);

        return CsvStreamExport::make('certificate-types-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders(['Name', 'Short code', 'Description', 'Templates', 'Origin'])
            ->streamFromCollection($rows);
    }

    /**
     * CSV export of a bulk selection from the Certificate Templates screen.
     *
     * Ids are treated as a request, never as data (normalised, capped,
     * re-queried inside the active college through the model's college scope)
     * and `certificate_templates.manage` is re-checked here. The body is the
     * plain-text template exactly as stored. Adding or revising a template
     * stays on its own screen.
     */
    public function exportTemplates(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->permit('certificate_templates.manage');

        $ids = ListSelection::ids($request->input('ids', []));

        $templates = CertificateTemplate::query()
            ->with('type:id,name,code')
            ->whereIn('certificate_templates.id', $ids)
            ->orderBy('certificate_templates.id')
            ->get();

        $rows = $templates->map(fn (CertificateTemplate $template): array => [
            $template->name,
            $template->type?->name,
            $template->body,
        ]);

        $audit->record('certificate_templates.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $templates->count(),
        ]);

        return CsvStreamExport::make('certificate-templates-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders(['Name', 'Type', 'Body'])
            ->streamFromCollection($rows);
    }

    public function show(int $certificate)
    {
        $this->permit('certificates.view');
        $model = Certificate::with(['student', 'enrollment', 'transfer', 'type'])->findOrFail($certificate);
        return view('certificates.show', ['certificate' => $model, 'templates' => CertificateTemplate::where('certificate_type_id', $model->certificate_type_id)->get()]);
    }

    public function generate(Request $request, int $certificate, CertificateWorkflow $workflow)
    {
        $this->permit('certificates.generate');
        $data = $request->validate(['certificate_template_id' => ['required', 'integer']]);
        $workflow->generate($certificate, $data['certificate_template_id']);
        return back()->with('success', 'Certificate generated. Review the draft before issuance.');
    }

    public function issue(int $certificate, CertificateWorkflow $workflow)
    {
        $this->permit('certificates.issue');
        $workflow->issue($certificate);
        return back()->with('success', 'Certificate issued.');
    }

    public function verify(Request $request, CertificateWorkflow $workflow)
    {
        $this->permit('certificates.verify');
        $data = $request->validate(['number' => ['required', 'string', 'max:100']]);
        $certificate = $workflow->verify($data['number']);
        return view('certificates.verified', compact('certificate'));
    }

    public function types()
    {
        $this->permit('certificate_types.manage');
        $this->provision();
        return view('certificates.types', ['types' => CertificateType::withCount('templates')->orderBy('id')->get()]);
    }

    public function storeType(Request $request, AuditLogService $audit)
    {
        $this->permit('certificate_types.manage');
        $this->provision();
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z][A-Z0-9_]*$/', Rule::unique('certificate_types', 'code')->where('college_id', app(TenantContext::class)->id())],
            'description' => ['required', 'string', 'max:2000'],
        ]);
        $type = CertificateType::create($data);
        $audit->record('certificate_type.created', $type, [], $data);
        return back()->with('success', 'Certificate type created. Add one or more templates under Certificate Templates.');
    }

    public function templates()
    {
        $this->permit('certificate_templates.manage');
        $this->provision();
        return view('certificates.templates', ['types' => CertificateType::orderBy('id')->get(), 'templates' => CertificateTemplate::with('type')->latest('id')->paginate(20)]);
    }

    public function storeTemplate(Request $request, AuditLogService $audit)
    {
        $this->permit('certificate_templates.manage');
        $data = $request->validate(['certificate_type_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:255'], 'body' => ['required', 'string', 'max:20000']]);
        CertificateType::findOrFail($data['certificate_type_id']);
        preg_match_all('/\{\{\s*(.*?)\s*\}\}/s', $data['body'], $matches);
        if (array_diff($matches[1], CertificateWorkflow::PLACEHOLDERS)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['body' => 'Use only the supported placeholders listed below.']);
        }
        $template = CertificateTemplate::create($data);
        $audit->record('certificate_template.created', $template, [], ['name' => $template->name, 'certificate_type_id' => $template->certificate_type_id]);
        return back()->with('success', 'Template added. Existing generated documents remain unchanged.');
    }

    public function reports(Request $request)
    {
        return app(CertificateReportController::class)->index($request);
    }
}
