<?php

namespace App\Http\Controllers;

use App\Domain\Student\Services\StudentDocumentService;
use App\Http\Requests\StudentDocument\StoreStudentDocumentRequest;
use App\Http\Requests\StudentDocument\UpdateStudentDocumentRequest;
use App\Http\Requests\StudentDocument\VerifyStudentDocumentRequest;
use App\Models\AdmissionDocumentType;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Services\Audit\AuditLogService;
use App\Services\Files\SecureFileService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Student documents: secure upload, verification, replacement, download.
 *
 * Every access path re-resolves the document through CollegeScope (a foreign
 * college's id 404s) and authorizes before touching the file. The stored path
 * is server-generated and re-checked on read, and files live on the private
 * disk so they are never reachable by URL.
 */
class StudentDocumentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', StudentDocument::class);

        $query = StudentDocument::query()
            ->with(['student', 'documentType', 'verifiedBy'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('original_filename', 'like', "%{$search}%")
                  ->orWhereHas('student', function ($sq) use ($search): void {
                      $sq->where('student_number', 'like', "%{$search}%")
                         ->orWhere('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%");
                  });
            });
        }

        if ($studentId = $request->input('student_id')) {
            $query->where('student_id', $studentId);
        }

        if (in_array($request->input('verification_status'), StudentDocument::VERIFICATION_STATUSES, true)) {
            $query->where('verification_status', $request->input('verification_status'));
        }

        if ($documentTypeId = $request->input('document_type_id')) {
            $query->where('document_type_id', $documentTypeId);
        }

        return view('student_documents.index', [
            'documents' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'student_id' => $request->input('student_id'),
            'verification_status' => $request->input('verification_status'),
            'document_type_id' => $request->input('document_type_id'),
            'students' => $this->studentOptions(),
            'documentTypes' => $this->documentTypeOptions(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', StudentDocument::class);

        return view('student_documents.create', [
            'students' => $this->studentOptions(),
            'documentTypes' => $this->documentTypeOptions(),
            'selectedStudentId' => $request->input('student_id'),
        ]);
    }

    public function store(StoreStudentDocumentRequest $request, StudentDocumentService $service): RedirectResponse
    {
        $collegeId = app(TenantContext::class)->id();

        $document = $service->store($request->validated(), $request->file('file'), $collegeId, auth()->id());

        return redirect()->route('student-documents.index')
            ->with('success', 'Document "'.$document->title.'" uploaded for '.$document->student->student_number.'.');
    }

    public function edit(string $student_document): View
    {
        $model = $this->findScoped($student_document);
        $this->authorize('update', $model);

        return view('student_documents.edit', [
            'document' => $model->load(['student', 'documentType', 'verifiedBy']),
            'students' => $this->studentOptions(),
            'documentTypes' => $this->documentTypeOptions(),
        ]);
    }

    public function update(UpdateStudentDocumentRequest $request, string $student_document, StudentDocumentService $service): RedirectResponse
    {
        $model = $this->findScoped($student_document);

        if ($request->hasFile('file')) {
            // Replacing the file resets verification — a new file is unverified.
            $service->reupload($model, $request->file('file'), auth()->id());
        }

        $service->updateMetadata($model, $request->validated(), auth()->id());

        return back()->with('success', 'Document updated.');
    }

    public function verify(VerifyStudentDocumentRequest $request, string $student_document, StudentDocumentService $service): RedirectResponse
    {
        $model = $this->findScoped($student_document);

        if ($request->input('action') === 'verify') {
            $service->verify($model, (int) auth()->id(), $request->input('remarks'));

            return back()->with('success', 'Document verified.');
        }

        if ($request->input('action') === 'reject') {
            $service->reject($model, (int) auth()->id(), (string) $request->input('rejection_remarks'));

            return back()->with('success', 'Document rejected.');
        }

        return back()->withErrors(['action' => 'Invalid action.']);
    }

    public function destroy(string $student_document, StudentDocumentService $service): RedirectResponse
    {
        $model = $this->findScoped($student_document);
        $this->authorize('delete', $model);

        $service->delete($model, auth()->id());

        return redirect()->route('student-documents.index')->with('success', 'Document deleted.');
    }

    /**
     * Stream a document from the private disk.
     *
     * Authorization first, then a path sanity check (defence in depth on a
     * server-generated value), then an existence check. The download is audited
     * without ever logging file content.
     */
    public function download(string $student_document, SecureFileService $files, AuditLogService $audit): StreamedResponse
    {
        $model = $this->findScoped($student_document);
        $this->authorize('download', $model);

        $path = StudentDocumentService::assertSafePath($model->file_path);

        if (! Storage::disk('private')->exists($path)) {
            abort(404, 'The stored file is no longer available.');
        }

        $audit->record('student_document.downloaded', $model, [], [
            'id' => $model->id,
            'title' => $model->title,
            'file_path' => $path,
        ]);

        return $files->download($path, StudentDocumentService::safeDownloadName($model->original_filename, 'student-document'));
    }

    private function findScoped(string $id): StudentDocument
    {
        return StudentDocument::query()->findOrFail($id);
    }

    private function studentOptions()
    {
        return Student::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'student_number', 'first_name', 'last_name']);
    }

    /**
     * Document types are REUSED from the Admissions module (single source of
     * truth per college) rather than duplicated for students.
     */
    private function documentTypeOptions()
    {
        return AdmissionDocumentType::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']);
    }
}
