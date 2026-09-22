<?php

namespace App\Http\Controllers;

use App\Http\Requests\Designation\StoreDesignationRequest;
use App\Http\Requests\Designation\UpdateDesignationRequest;
use App\Models\Designation;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DesignationController extends Controller
{
    private const AUDITED = ['id', 'name', 'code', 'description', 'status'];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Designation::class);

        $query = Designation::query()
            ->withCount('employees')
            ->orderBy('name');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if (in_array($request->input('status'), Designation::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return view('designations.index', [
            'designations' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'status' => $request->input('status'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Designation::class);

        return view('designations.create');
    }

    public function store(StoreDesignationRequest $request, AuditLogService $audit): RedirectResponse
    {
        $designation = Designation::create($request->validated() + [
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $audit->record('designation.created', $designation, [], $designation->only(self::AUDITED));

        return redirect()->route('designations.index')->with('success', 'Designation created.');
    }

    public function show(string $designation): View
    {
        $model = $this->findScoped($designation);
        $this->authorize('view', $model);

        return view('designations.show', [
            'designation' => $model->load('employees'),
        ]);
    }

    public function edit(string $designation): View
    {
        $model = $this->findScoped($designation);
        $this->authorize('update', $model);

        return view('designations.edit', ['designation' => $model]);
    }

    public function update(UpdateDesignationRequest $request, string $designation, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($designation);
        $old = $model->only(self::AUDITED);
        $model->update($request->validated() + ['updated_by' => auth()->id()]);
        $audit->record('designation.updated', $model, $old, $model->only(self::AUDITED));

        return redirect()->route('designations.index')->with('success', 'Designation updated.');
    }

    public function destroy(string $designation, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($designation);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('designation.deleted', $model, $snapshot, []);

        return redirect()->route('designations.index')->with('success', 'Designation deleted.');
    }

    private function findScoped(string $id): Designation
    {
        return Designation::query()->findOrFail($id);
    }
}
