<?php

namespace App\Http\Controllers;

use App\Domain\Library\Services\PublisherService;
use App\Http\Requests\Publisher\StorePublisherRequest;
use App\Http\Requests\Publisher\UpdatePublisherRequest;
use App\Models\Publisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Publishers (Library Management → Authors / Publishers).
 *
 * Thin controller: validation lives in the Form Requests, the duplicate-name,
 * in-use and tenant rules live in PublisherService, and college_id always
 * comes from the tenant context — never from request data.
 */
class PublisherController extends Controller
{
    public function __construct(private readonly PublisherService $publishers)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Publisher::class);

        $query = Publisher::query()
            ->withCount('books')
            // Deterministic pagination order.
            ->orderBy('name')
            ->orderBy('id');

        $search = trim((string) $request->input('search'));

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (in_array($request->input('status'), Publisher::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        return view('publishers.index', [
            'publishers' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $request->input('status'),
            'statuses' => Publisher::STATUSES,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Publisher::class);

        return view('publishers.create', ['statuses' => Publisher::STATUSES]);
    }

    public function store(StorePublisherRequest $request): RedirectResponse
    {
        $college = app(TenantContext::class)->require();

        $publisher = $this->publishers->create($college, $request->validated(), $request->user());

        return redirect()
            ->route('publishers.index')
            ->with('success', "Publisher \"{$publisher->name}\" created.");
    }

    public function edit(string $publisher): View
    {
        $model = $this->findScoped($publisher);
        $this->authorize('update', $model);

        return view('publishers.edit', [
            'publisher' => $model->loadCount('books'),
            'statuses' => Publisher::STATUSES,
        ]);
    }

    public function update(UpdatePublisherRequest $request, string $publisher): RedirectResponse
    {
        $model = $this->findScoped($publisher);

        $model = $this->publishers->update($model, $request->validated(), $request->user());

        return redirect()
            ->route('publishers.index')
            ->with('success', "Publisher \"{$model->name}\" updated.");
    }

    public function destroy(string $publisher): RedirectResponse
    {
        $model = $this->findScoped($publisher);
        $this->authorize('delete', $model);

        $name = $model->name;
        $this->publishers->delete($model, request()->user());

        return redirect()
            ->route('publishers.index')
            ->with('success', "Publisher \"{$name}\" deleted.");
    }

    /**
     * Resolve the publisher INSIDE the controller, under the active tenant: the
     * project never relies on implicit route model binding for tenant-scoped
     * models (SubstituteBindings runs before the tenant middleware).
     */
    private function findScoped(string $id): Publisher
    {
        return Publisher::query()->findOrFail($id);
    }
}
