<?php

namespace App\Http\Controllers\Transport;

use App\Domain\Transport\Services\TransportMasterService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transport\TransportMasterRequest;
use App\Models\{Faculty, TransportDriver, TransportRoute, TransportStop};
use Illuminate\Http\Request;

/** Explicit scoped resolution happens after tenant middleware, never implicit binding. */
abstract class TransportMasterController extends Controller
{
    public string $model;
    public string $title;
    public string $routeName;

    public function __construct(private readonly TransportMasterService $masters) {}

    public function resolveParent(Request $request): ?TransportRoute
    {
        return $this->model === TransportStop::class
            ? TransportRoute::query()->findOrFail($request->route('transport_route')) : null;
    }

    public function resolveRecord(Request $request)
    {
        $parent = $this->resolveParent($request);
        if (! $request->route('record')) {
            return null;
        }

        return $this->model::query()->when($parent, fn ($q) => $q->where('route_id', $parent->id))->findOrFail($request->route('record'));
    }

    private function viewData(Request $request): array
    {
        return ['model' => $this->model, 'title' => $this->title, 'routeName' => $this->routeName,
            'parent' => $this->resolveParent($request), 'fields' => $this->model::FIELDS, 'statuses' => $this->model::STATUSES];
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', $this->model);
        $parent = $this->resolveParent($request);
        $query = $this->model::query()->when($parent, fn ($q) => $q->where('route_id', $parent->id));
        $search = trim((string) $request->input('search'));
        $searchFields = array_intersect(array_keys($this->model::FIELDS), ['name', 'code', 'registration_number', 'license_number']);
        if ($search !== '') {
            $query->where(function ($q) use ($searchFields, $search) {
                foreach ($searchFields as $field) {
                    $q->orWhere($field, 'like', "%{$search}%");
                }
            });
        }
        if (in_array($request->input('status'), $this->model::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }
        if ($this->model === TransportDriver::class) {
            $query->with('faculty');
        }
        $order = $parent ? 'sequence' : (isset($this->model::FIELDS['name']) ? 'name' : array_key_first($this->model::FIELDS));

        return view('transport.index', $this->viewData($request) + ['records' => $query->orderBy($order)->orderBy('id')->paginate(15)->withQueryString()]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', $this->model);
        return $this->form($request);
    }

    public function edit(Request $request)
    {
        $record = $this->resolveRecord($request);
        $this->authorize('update', $record);
        return $this->form($request, $record);
    }

    private function form(Request $request, $record = null)
    {
        return view('transport.form', $this->viewData($request) + ['record' => $record,
            'staff' => $this->model === TransportDriver::class ? Faculty::query()->orderBy('first_name')->orderBy('id')->get() : collect()]);
    }

    public function store(TransportMasterRequest $request)
    {
        $this->masters->save(new $this->model, $request->validated(), $request->user(), $this->resolveParent($request));
        return $this->backToIndex($request, 'Record created.');
    }

    public function update(TransportMasterRequest $request)
    {
        $this->masters->save($this->resolveRecord($request), $request->validated(), $request->user(), $this->resolveParent($request));
        return $this->backToIndex($request, 'Record updated.');
    }

    public function destroy(Request $request)
    {
        $record = $this->resolveRecord($request);
        $this->authorize('delete', $record);
        $this->masters->delete($record, $request->user());
        return $this->backToIndex($request, 'Record deleted.');
    }

    private function backToIndex(Request $request, string $message)
    {
        return redirect()->route($this->routeName.'.index', $this->resolveParent($request) ? ['transport_route' => $this->resolveParent($request)->id] : [])->with('success', $message);
    }
}
