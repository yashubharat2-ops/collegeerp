<?php

namespace App\Http\Controllers;

use App\Domain\Student\Services\StudentDocumentService;
use App\Domain\Student\Services\StudentTransferService;
use App\Http\Requests\StudentTransfer\IssueStudentTransferRequest;
use App\Http\Requests\StudentTransfer\StoreStudentTransferRequest;
use App\Http\Requests\StudentTransfer\UpdateStudentTransferRequest;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentTransfer;
use App\Services\Audit\AuditLogService;
use App\Services\Files\SecureFileService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Student transfer / Transfer Certificate workflow.
 *
 * request → approve → issue TC (student + enrollment become `withdrawn`).
 *
 * Nothing is ever deleted here: the Student record and its enrollments,
 * academic records and documents remain, because a transferred student is still
 * part of the institution's history. Every transition is authorized, tenant
 * scoped, transactional (in the service) and audited.
 */
class StudentTransferController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', StudentTransfer::class);

        $query = StudentTransfer::query()
            ->with(['student', 'enrollment', 'approver'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('tc_number', 'like', "%{$search}%")
                  ->orWhere('destination_institution', 'like', "%{$search}%")
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

        if (in_array($request->input('status'), StudentTransfer::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        if (in_array($request->input('tc_status'), StudentTransfer::TC_STATUSES, true)) {
            $query->where('tc_status', $request->input('tc_status'));
        }

        return view('student_transfers.index', [
            'transfers' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'student_id' => $request->input('student_id'),
            'status' => $request->input('status'),
            'tc_status' => $request->input('tc_status'),
            'students' => $this->studentOptions(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', StudentTransfer::class);

        return view('student_transfers.create', [
            'students' => $this->studentOptions(),
            'enrollments' => $this->enrollmentOptions(),
            'selectedStudentId' => $request->input('student_id'),
        ]);
    }

    public function store(StoreStudentTransferRequest $request, StudentTransferService $service): RedirectResponse
    {
        $collegeId = app(TenantContext::class)->id();

        $transfer = $service->create($request->validated(), $collegeId, auth()->id());

        return redirect()->route('student-transfers.index')
            ->with('success', 'Transfer request recorded for '.$transfer->student?->student_number.'.');
    }

    public function edit(string $student_transfer): View
    {
        $model = $this->findScoped($student_transfer);
        $this->authorize('update', $model);

        return view('student_transfers.edit', [
            'transfer' => $model->load(['student', 'enrollment']),
            'students' => $this->studentOptions(),
            'enrollments' => $this->enrollmentOptions(),
        ]);
    }

    public function update(UpdateStudentTransferRequest $request, string $student_transfer, StudentTransferService $service): RedirectResponse
    {
        $model = $this->findScoped($student_transfer);
        $collegeId = app(TenantContext::class)->id();

        $service->update($model, $request->validated(), $collegeId, auth()->id());

        return redirect()->route('student-transfers.index')->with('success', 'Transfer request updated.');
    }

    public function approve(string $student_transfer, StudentTransferService $service): RedirectResponse
    {
        $model = $this->findScoped($student_transfer);
        $this->authorize('approve', $model);

        $service->approve($model, app(TenantContext::class)->id(), auth()->id());

        return back()->with('success', 'Transfer request approved. Issue the TC to complete the process.');
    }

    public function reject(Request $request, string $student_transfer, StudentTransferService $service): RedirectResponse
    {
        $model = $this->findScoped($student_transfer);
        $this->authorize('approve', $model);

        $validated = $request->validate(['remarks' => ['nullable', 'string', 'max:2000']]);

        $service->reject($model, app(TenantContext::class)->id(), auth()->id(), $validated['remarks'] ?? null);

        return back()->with('success', 'Transfer request rejected.');
    }

    public function issue(IssueStudentTransferRequest $request, string $student_transfer, StudentTransferService $service): RedirectResponse
    {
        $model = $this->findScoped($student_transfer);

        $collegeId = app(TenantContext::class)->id();

        $issued = $service->issue(
            $model,
            $collegeId,
            auth()->id(),
            $request->input('tc_issue_date'),
            $request->file('tc_file'),
        );

        return redirect()->route('student-transfers.index')
            ->with('success', 'Transfer certificate '.$issued->tc_number.' issued. The student record and history are preserved.');
    }

    public function cancel(Request $request, string $student_transfer, StudentTransferService $service): RedirectResponse
    {
        $model = $this->findScoped($student_transfer);
        $this->authorize('cancel', $model);

        $validated = $request->validate(['remarks' => ['nullable', 'string', 'max:2000']]);

        $service->cancel($model, app(TenantContext::class)->id(), auth()->id(), $validated['remarks'] ?? null);

        return back()->with('success', 'Transfer request cancelled.');
    }

    public function destroy(string $student_transfer, StudentTransferService $service): RedirectResponse
    {
        $model = $this->findScoped($student_transfer);
        $this->authorize('delete', $model);

        $service->delete($model, app(TenantContext::class)->id(), auth()->id());

        return redirect()->route('student-transfers.index')->with('success', 'Transfer request deleted.');
    }

    /**
     * Download the uploaded TC copy from the private disk.
     *
     * Authorization first, then the same path sanity guard used for student
     * documents, then an existence check. The download is audited.
     */
    public function download(string $student_transfer, SecureFileService $files, AuditLogService $audit): StreamedResponse
    {
        $model = $this->findScoped($student_transfer);
        $this->authorize('download', $model);

        if (! $model->hasTcFile()) {
            abort(404, 'No TC file has been attached to this request.');
        }

        $path = StudentDocumentService::assertSafePath($model->tc_file_path);

        if (! Storage::disk('private')->exists($path)) {
            abort(404, 'The stored TC file is no longer available.');
        }

        $audit->record('student_transfer.downloaded', $model, [], [
            'id' => $model->id,
            'tc_number' => $model->tc_number,
            'file_path' => $path,
        ]);

        return $files->download($path, StudentDocumentService::safeDownloadName($model->tc_original_filename, 'transfer-certificate'));
    }

    private function findScoped(string $id): StudentTransfer
    {
        return StudentTransfer::query()->findOrFail($id);
    }

    private function studentOptions()
    {
        return Student::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'student_number', 'first_name', 'last_name', 'status']);
    }

    private function enrollmentOptions()
    {
        return StudentEnrollment::query()
            ->with(['student:id,student_number', 'academicYear:id,name', 'program:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }
}
