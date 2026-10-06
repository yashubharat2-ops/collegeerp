<?php

namespace App\Http\Controllers;

use App\Domain\Student\Services\StudentImportService;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dedicated Student Bulk Registration / Import workflow.
 *
 * Separate from Student List bulk actions. Authorization is the Student
 * create permission; the optional first enrollment additionally requires
 * StudentEnrollment create, matching the single Student form.
 */
class StudentImportController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('create', Student::class);

        return view('students.import', [
            'preview' => session('student_import_preview'),
            'token' => session('student_import_token'),
        ]);
    }

    public function template(StudentImportService $import): StreamedResponse
    {
        $this->authorize('create', Student::class);

        return response()->streamDownload(function () use ($import): void {
            $handle = $import->templateHandle();
            fpassthru($handle);
            fclose($handle);
        }, 'student-import-template.csv', [
            'Content-Type' => 'text/csv; charset=utf-8',
        ]);
    }

    public function validateUpload(Request $request, StudentImportService $import): RedirectResponse
    {
        $this->authorize('create', Student::class);

        $request->validate([
            'file' => ['required', 'file', 'extensions:csv,txt', 'max:'.StudentImportService::MAX_KB],
        ]);

        $collegeId = app(TenantContext::class)->id();
        $uploaded = $request->file('file');
        $canEnroll = (bool) $request->user()?->can('create', StudentEnrollment::class);

        $preview = $import->validatePath($uploaded->getRealPath(), $collegeId, $canEnroll);

        if (! $preview['ok']) {
            return redirect()
                ->route('students.import.index')
                ->with('student_import_preview', $preview);
        }

        $token = $import->storeUpload(
            $uploaded->getRealPath(),
            $collegeId,
            (int) $request->user()->id,
        );

        return redirect()
            ->route('students.import.index')
            ->with('student_import_preview', $preview)
            ->with('student_import_token', $token);
    }

    public function store(Request $request, StudentImportService $import): RedirectResponse
    {
        $this->authorize('create', Student::class);

        $request->validate([
            'token' => ['required', 'string', 'max:80'],
        ]);

        $collegeId = app(TenantContext::class)->id();
        $pending = $import->pending($request->string('token')->toString());

        abort_unless(
            is_array($pending)
            && (int) $pending['college_id'] === $collegeId
            && (int) $pending['user_id'] === (int) $request->user()->id,
            404
        );

        $absolute = Storage::disk(StudentImportService::DISK)->path($pending['path']);
        $canEnroll = (bool) $request->user()?->can('create', StudentEnrollment::class);

        try {
            $result = $import->importPath($absolute, $collegeId, $canEnroll);
        } finally {
            $import->forget($request->string('token')->toString());
        }

        return redirect()
            ->route('students.index')
            ->with('success', $result['created'].' student(s) imported.');
    }
}
