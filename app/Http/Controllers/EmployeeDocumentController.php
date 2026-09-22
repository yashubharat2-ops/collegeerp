<?php

namespace App\Http\Controllers;

use App\Domain\HR\Services\EmployeeDocumentService;
use App\Http\Requests\EmployeeDocument\StoreEmployeeDocumentRequest;
use App\Http\Requests\EmployeeDocument\UpdateEmployeeDocumentRequest;
use App\Models\AdmissionDocumentType;
use App\Models\EmployeeDocument;
use App\Models\Faculty;
use App\Services\Audit\AuditLogService;
use App\Services\Files\SecureFileService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocumentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', EmployeeDocument::class);

        $query = EmployeeDocument::query()
            ->with(['employee', 'documentTypeMaster'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('document_name', 'like', "%{$search}%")
                    ->orWhere('document_type', 'like', "%{$search}%")
                    ->orWhere('original_filename', 'like', "%{$search}%")
                    ->orWhereHas('employee', function ($employee) use ($search): void {
                        $employee->where('employee_code', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        if ($employeeId = $request->input('employee_id', $request->input('faculty_id'))) {
            $query->where('faculty_id', $employeeId);
        }

        if ($documentType = trim((string) $request->input('document_type'))) {
            $query->where('document_type', $documentType);
        }

        return view('employee_documents.index', [
            'documents' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'employee_id' => $request->input('employee_id', $request->input('faculty_id')),
            'document_type' => $request->input('document_type'),
            'employees' => $this->employeeOptions(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', EmployeeDocument::class);

        return view('employee_documents.create', [
            'employees' => $this->employeeOptions(),
            'documentTypes' => $this->documentTypeOptions(),
            'selectedEmployeeId' => $request->input('employee_id', $request->input('faculty_id')),
        ]);
    }

    public function store(StoreEmployeeDocumentRequest $request, EmployeeDocumentService $service): RedirectResponse
    {
        $document = $service->store(
            $request->validated(),
            $request->file('file'),
            (int) app(TenantContext::class)->id(),
            auth()->id(),
        );

        return redirect()->route('employee-documents.index')
            ->with('success', 'Employee document uploaded.');
    }

    public function show(string $employee_document): View
    {
        $model = $this->findScoped($employee_document);
        $this->authorize('view', $model);

        return view('employee_documents.show', [
            'document' => $model->load(['employee', 'documentTypeMaster', 'uploadedBy']),
        ]);
    }

    public function edit(string $employee_document): View
    {
        $model = $this->findScoped($employee_document);
        $this->authorize('update', $model);

        return view('employee_documents.edit', [
            'document' => $model->load(['employee', 'documentTypeMaster']),
            'employees' => $this->employeeOptions(),
            'documentTypes' => $this->documentTypeOptions(),
        ]);
    }

    public function update(UpdateEmployeeDocumentRequest $request, string $employee_document, EmployeeDocumentService $service): RedirectResponse
    {
        $model = $this->findScoped($employee_document);
        $this->authorize('update', $model);

        if ($request->hasFile('file')) {
            $service->reupload($model, $request->file('file'), auth()->id());
        }

        $service->updateMetadata($model, $request->validated(), auth()->id());

        return redirect()->route('employee-documents.index')->with('success', 'Employee document updated.');
    }

    public function destroy(string $employee_document, EmployeeDocumentService $service): RedirectResponse
    {
        $model = $this->findScoped($employee_document);
        $this->authorize('delete', $model);
        $service->delete($model, auth()->id());

        return redirect()->route('employee-documents.index')->with('success', 'Employee document deleted.');
    }

    public function download(string $employee_document, SecureFileService $files, AuditLogService $audit): StreamedResponse
    {
        $model = $this->findScoped($employee_document);
        $this->authorize('download', $model);

        $path = EmployeeDocumentService::assertSafePath(
            $model->file_path,
            (int) $model->college_id,
            (int) $model->faculty_id,
        );
        if (! Storage::disk('private')->exists($path)) {
            abort(404, 'The stored file is no longer available.');
        }

        $audit->record('employee_document.downloaded', $model, [], [
            'id' => $model->id,
            'document_name' => $model->document_name,
            'file_path' => $path,
        ]);

        return $files->download(
            $path,
            EmployeeDocumentService::safeDownloadName($model->original_filename),
        );
    }

    private function findScoped(string $id): EmployeeDocument
    {
        return EmployeeDocument::query()->findOrFail($id);
    }

    private function employeeOptions()
    {
        return Faculty::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'employee_code', 'first_name', 'middle_name', 'last_name']);
    }

    private function documentTypeOptions()
    {
        return AdmissionDocumentType::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }
}
