<?php

namespace App\Http\Controllers;

use App\Http\Requests\Designation\StoreDesignationRequest;
use App\Http\Requests\Designation\UpdateDesignationRequest;
use App\Models\Designation;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * CSV export of a bulk selection from the Designations list.
     *
     * Ids are treated as a request, never as data (normalised, capped, re-queried
     * inside the active college through the model's college scope) and
     * `designations.view` is re-checked here. The employee count is the same live
     * count the listing shows and nothing is written.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', Designation::class);

        $ids = ListSelection::ids($request->input('ids', []));

        $designations = Designation::query()
            ->withCount('employees')
            ->whereIn('designations.id', $ids)
            ->orderBy('designations.name')
            ->orderBy('designations.id')
            ->get();

        $rows = $designations->map(fn (Designation $designation): array => [
            $designation->name,
            $designation->code,
            $designation->description,
            $designation->employees_count,
            $designation->status,
        ]);

        $audit->record('designations.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $designations->count(),
        ]);

        return CsvStreamExport::make('designations-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders(['Name', 'Code', 'Description', 'Employees', 'Status'])
            ->streamFromCollection($rows);
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
