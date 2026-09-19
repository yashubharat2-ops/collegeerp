<?php

namespace App\Http\Controllers;

use App\Http\Requests\Subject\StoreSubjectRequest;
use App\Http\Requests\Subject\UpdateSubjectRequest;
use App\Models\Department;
use App\Models\Subject;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubjectController extends Controller
{
    private const AUDITED = [
        'id',
        'department_id',
        'code',
        'name',
        'short_name',
        'subject_type',
        'credits',
        'max_marks',
        'passing_marks',
        'status',
        'description',
    ];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Subject::class);

        $query = Subject::query()
            ->with('department')
            ->orderBy('name');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('short_name', 'like', "%{$search}%");
            });
        }

        if ($departmentId = $request->input('department_id')) {
            $query->where('department_id', $departmentId);
        }

        if ($type = $request->input('subject_type')) {
            if (in_array($type, Subject::TYPES, true)) {
                $query->where('subject_type', $type);
            }
        }

        if (in_array($request->input('status'), Subject::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return view('subjects.index', [
            'subjects' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'department_id' => $request->input('department_id'),
            'subject_type' => $request->input('subject_type'),
            'status' => $request->input('status'),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name', 'code']),
            'types' => Subject::TYPES,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Subject::class);

        return view('subjects.create', [
            'departments' => Department::query()->orderBy('name')->get(['id', 'name', 'code']),
            'types' => Subject::TYPES,
        ]);
    }

    public function store(StoreSubjectRequest $request, AuditLogService $audit): RedirectResponse
    {
        $subject = Subject::create($request->validated() + [
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $audit->record('subject.created', $subject, [], $subject->only(self::AUDITED));

        return redirect()->route('subjects.index')->with('success', 'Subject created.');
    }

    public function edit(string $subject): View
    {
        $model = $this->findScoped($subject);
        $this->authorize('update', $model);

        return view('subjects.edit', [
            'subject' => $model,
            'departments' => Department::query()->orderBy('name')->get(['id', 'name', 'code']),
            'types' => Subject::TYPES,
        ]);
    }

    public function update(UpdateSubjectRequest $request, string $subject, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($subject);
        $old = $model->only(self::AUDITED);

        $model->update($request->validated() + ['updated_by' => auth()->id()]);
        $audit->record('subject.updated', $model, $old, $model->only(self::AUDITED));

        return redirect()->route('subjects.index')->with('success', 'Subject updated.');
    }

    public function destroy(string $subject, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($subject);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('subject.deleted', $model, $snapshot, []);

        return redirect()->route('subjects.index')->with('success', 'Subject deleted.');
    }

    private function findScoped(string $id): Subject
    {
        return Subject::query()->findOrFail($id);
    }
}
