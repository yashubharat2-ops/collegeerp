<?php

namespace App\Http\Controllers;

use App\Domain\Admission\Services\AdmissionDocumentService;
use App\Http\Requests\AdmissionDocument\StoreAdmissionDocumentRequest;
use App\Http\Requests\AdmissionDocument\UpdateAdmissionDocumentRequest;
use App\Http\Requests\AdmissionDocument\VerifyAdmissionDocumentRequest;
use App\Models\AcademicYear;
use App\Models\AdmissionApplicant;
use App\Models\AdmissionApplication;
use App\Models\AdmissionDocument;
use App\Models\AdmissionDocumentType;
use App\Models\Program;
use App\Services\Audit\AuditLogService;
use App\Services\Files\SecureFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdmissionDocumentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', AdmissionDocument::class);

        $query = AdmissionDocument::query()
            ->with(['applicant', 'application', 'documentType'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('original_filename', 'like', "%{$search}%")
                  ->orWhereHas('applicant', function ($aq) use ($search): void {
                      $aq->where('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        if (in_array($request->input('verification_status'), AdmissionDocument::VERIFICATION_STATUSES, true)) {
            $query->where('verification_status', $request->input('verification_status'));
        }

        if ($documentTypeId = $request->input('document_type_id')) {
            $query->where('document_type_id', $documentTypeId);
        }

        if ($applicantId = $request->input('applicant_id')) {
            $query->where('applicant_id', $applicantId);
        }

        if ($applicationId = $request->input('application_id')) {
            $query->where('application_id', $applicationId);
        }

        return view('admission_documents.index', [
            'documents' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'verification_status' => $request->input('verification_status'),
            'document_type_id' => $request->input('document_type_id'),
            'applicant_id' => $request->input('applicant_id'),
            'application_id' => $request->input('application_id'),
            'documentTypes' => $this->documentTypeOptions(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', AdmissionDocument::class);

        return view('admission_documents.create', [
            'documentTypes' => $this->documentTypeOptions(),
            'applicants' => $this->applicantOptions(),
            'applications' => $this->applicationOptions(),
        ]);
    }

    public function store(StoreAdmissionDocumentRequest $request, AdmissionDocumentService $service): RedirectResponse
    {
        $collegeId = app(\App\Support\Tenancy\TenantContext::class)->id();

        $document = $service->store(
            $request->validated(),
            $request->file('file'),
            $collegeId,
            auth()->id()
        );

        return redirect()->route('admission-documents.index')->with('success', 'Document '.$document->original_filename.' uploaded.');
    }

    public function edit(string $admission_document): View
    {
        $model = $this->findScoped($admission_document);
        $this->authorize('update', $model);

        return view('admission_documents.edit', [
            'document' => $model->load(['applicant','application','documentType']),
            'documentTypes' => $this->documentTypeOptions(),
        ]);
    }

    public function update(UpdateAdmissionDocumentRequest $request, string $admission_document, AdmissionDocumentService $service): RedirectResponse
    {
        $model = $this->findScoped($admission_document);

        if ($request->hasFile('file')) {
            $service->reupload($model, $request->file('file'), auth()->id(), $request->input('remarks'));
        } else {
            $old = $model->only(['remarks']);
            $model->update(['remarks' => $request->input('remarks')]);
            app(AuditLogService::class)->record('admission_document.updated', $model, $old, $model->only(['remarks']));
        }

        return back()->with('success', 'Document updated.');
    }

    public function destroy(string $admission_document, AuditLogService $audit, SecureFileService $fileService): RedirectResponse
    {
        $model = $this->findScoped($admission_document);
        $this->authorize('delete', $model);

        $snapshot = $model->only(['id','applicant_id','application_id','document_type_id','file_path','original_filename']);
        $filePath = $model->file_path;

        $model->delete();
        $audit->record('admission_document.deleted', $model, $snapshot, []);

        // Delete file after DB soft delete (still keep path in snapshot for audit)
        // Note: file stays in private storage until hard deleted? We'll delete now for security.
        $fileService->delete($filePath);

        return back()->with('success', 'Document deleted.');
    }

    public function download(string $admission_document, SecureFileService $fileService)
    {
        $model = $this->findScoped($admission_document);
        $this->authorize('view', $model);

        // Reject unsafe persisted paths before any filesystem adapter sees them.
        $filePath = $model->rawFilePath();
        if (! AdmissionDocument::isSafeFilePath($filePath)) {
            abort(403, 'Invalid file path');
        }

        // Ensure file exists in private disk
        $disk = \Illuminate\Support\Facades\Storage::disk('private');
        if (! $disk->exists($filePath)) {
            abort(404, 'File not found');
        }

        // Audit download (without sensitive file content)
        app(AuditLogService::class)->record('admission_document.downloaded', $model, [], [
            'id' => $model->id,
            'file_path' => $filePath,
        ]);

        return $fileService->download($filePath);
    }

    public function verify(VerifyAdmissionDocumentRequest $request, string $admission_document, AdmissionDocumentService $service): RedirectResponse
    {
        $model = $this->findScoped($admission_document);

        $action = $request->input('action');

        if ($action === 'verify') {
            $service->verify($model, auth()->id(), $request->input('remarks'));
            return back()->with('success', 'Document verified.');
        }

        if ($action === 'reject') {
            $service->reject($model, auth()->id(), $request->input('rejection_remarks'));
            return back()->with('success', 'Document rejected.');
        }

        return back()->withErrors(['action' => 'Invalid action']);
    }

    private function findScoped(string $id): AdmissionDocument
    {
        return AdmissionDocument::query()->findOrFail($id);
    }

    private function documentTypeOptions()
    {
        return AdmissionDocumentType::query()->where('status', 'active')->orderBy('name')->get(['id','name','code']);
    }

    private function applicantOptions()
    {
        return AdmissionApplicant::query()->orderByDesc('created_at')->limit(50)->get(['id','first_name','last_name','email']);
    }

    private function applicationOptions()
    {
        return AdmissionApplication::query()->with('applicant:id,first_name,last_name')->orderByDesc('created_at')->limit(50)->get(['id','application_number','applicant_id']);
    }
}
