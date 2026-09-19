<?php

namespace App\Http\Controllers;

use App\Http\Requests\Campus\StoreCampusRequest;
use App\Http\Requests\Campus\UpdateCampusRequest;
use App\Models\Campus;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CampusController extends Controller
{
    private const AUDITED = ['id', 'name', 'code', 'short_name', 'address', 'city', 'state', 'pincode', 'phone', 'email', 'status', 'description'];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Campus::class);

        $query = Campus::query()->orderBy('name');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function (mixed $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('short_name', 'like', "%{$search}%");
            });
        }

        if (in_array($request->input('status'), ['active', 'inactive'], true)) {
            $query->where('status', $request->input('status'));
        }

        return view('campuses.index', [
            'campuses' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'status' => $request->input('status'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Campus::class);

        return view('campuses.create');
    }

    public function store(StoreCampusRequest $request, AuditLogService $audit, TenantContext $tenant): RedirectResponse
    {
        // college_id, created_by, updated_by are never taken from the browser.
        // BelongsToCollege will attach college_id from TenantContext, but we also
        // set it explicitly plus audit fields for clarity and server control.
        $data = $request->validated();
        $data['college_id'] = $tenant->id();
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        $campus = Campus::create($data);
        $audit->record('campus.created', $campus, [], $campus->only(self::AUDITED));

        return redirect()->route('campuses.index')->with('success', 'Campus created.');
    }

    public function edit(string $campus): View
    {
        $model = $this->findScoped($campus);
        $this->authorize('update', $model);

        return view('campuses.edit', ['campus' => $model]);
    }

    public function update(UpdateCampusRequest $request, string $campus, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($campus);

        $old = $model->only(self::AUDITED);
        $data = $request->validated();
        $data['updated_by'] = auth()->id();

        $model->update($data);
        $audit->record('campus.updated', $model, $old, $model->only(self::AUDITED));

        return redirect()->route('campuses.index')->with('success', 'Campus updated.');
    }

    public function destroy(string $campus, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($campus);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('campus.deleted', $model, $snapshot, []);

        return redirect()->route('campuses.index')->with('success', 'Campus deleted.');
    }

    /**
     * Tenant-safe lookup: Campus's CollegeScope is driven by the server-side
     * TenantContext (resolved by the 'tenant' middleware), so a record belonging
     * to another college is simply not found. Records are resolved here — not by
     * implicit route-model binding — because the tenant middleware runs after
     * SubstituteBindings in the web stack, exactly like the other Phase 0 modules
     * that never rely on bound models in routes.
     */
    private function findScoped(string $campus): Campus
    {
        return Campus::query()->findOrFail($campus);
    }
}
