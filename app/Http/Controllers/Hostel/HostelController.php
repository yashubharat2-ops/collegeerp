<?php

namespace App\Http\Controllers\Hostel;

use App\Domain\Hostel\Services\HostelService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hostel\StoreHostelRequest;
use App\Http\Requests\Hostel\UpdateHostelRequest;
use App\Models\Hostel;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Hostels (Hostel Management) — the hostel master.
 *
 * Thin controller: validation lives in the Form Requests, the duplicate /
 * in-use / tenant rules live in HostelService, and college_id always comes
 * from the tenant context — never from request data.
 */
class HostelController extends Controller
{
    public function __construct(private readonly HostelService $hostels)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Hostel::class);

        $query = Hostel::query()
            ->withCount(['buildings', 'rooms', 'beds'])
            // Deterministic pagination order.
            ->orderBy('name')
            ->orderBy('id');

        $search = trim((string) $request->input('search'));

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if (in_array($request->input('status'), Hostel::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        if (in_array($request->input('hostel_type'), Hostel::TYPES, true)) {
            $query->where('hostel_type', $request->input('hostel_type'));
        }

        if (in_array($request->input('gender'), Hostel::GENDERS, true)) {
            $query->where('gender', $request->input('gender'));
        }

        return view('hostels.index', [
            'hostels' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $request->input('status'),
            'statuses' => Hostel::STATUSES,
            'types' => Hostel::TYPES,
            'genders' => Hostel::GENDERS,
            'filters' => [
                'hostel_type' => $request->input('hostel_type'),
                'gender' => $request->input('gender'),
            ],
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Hostel::class);

        return view('hostels.create', [
            'statuses' => Hostel::STATUSES,
            'types' => Hostel::TYPES,
            'genders' => Hostel::GENDERS,
        ]);
    }

    public function store(StoreHostelRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();

        $hostel = $this->hostels->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('hostels.index')
            ->with('success', "Hostel \"{$hostel->name}\" created.");
    }

    public function edit(string $hostel): View
    {
        $model = $this->findScoped($hostel);
        $this->authorize('update', $model);

        return view('hostels.edit', [
            'hostel' => $model->loadCount(['buildings', 'rooms', 'beds']),
            'statuses' => Hostel::STATUSES,
            'types' => Hostel::TYPES,
            'genders' => Hostel::GENDERS,
        ]);
    }

    public function update(UpdateHostelRequest $request, string $hostel): RedirectResponse
    {
        $model = $this->findScoped($hostel);

        $model = $this->hostels->update($model, $request->validated(), $request->user());

        return redirect()
            ->route('hostels.index')
            ->with('success', "Hostel \"{$model->name}\" updated.");
    }

    public function destroy(string $hostel): RedirectResponse
    {
        $model = $this->findScoped($hostel);
        $this->authorize('delete', $model);

        $name = $model->name;
        $this->hostels->delete($model, request()->user());

        return redirect()
            ->route('hostels.index')
            ->with('success', "Hostel \"{$name}\" deleted.");
    }

    /**
     * Resolve the hostel INSIDE the controller, under the active tenant: the
     * project never relies on implicit route model binding for tenant-scoped
     * models (SubstituteBindings runs before the tenant middleware).
     */
    private function findScoped(string $id): Hostel
    {
        return Hostel::query()->findOrFail($id);
    }
}
