<?php

namespace App\Http\Controllers\Transport;

use App\Domain\Transport\Services\TransportMasterService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transport\TransportMasterRequest;
use App\Models\{Faculty, TransportDriver, TransportRoute, TransportStop, Vehicle};
use App\Services\Audit\AuditLogService;
use App\Support\Export\CsvStreamExport;
use App\Support\Listing\ListSelection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'parent' => $this->resolveParent($request), 'fields' => $this->model::FIELDS, 'statuses' => $this->model::STATUSES,
            'bulkModule' => $this->bulkModule(), 'bulkPermission' => $this->bulkPermission()];
    }

    /**
     * The shared bulk-action module key of this master (what the listing's
     * bulk bar posts to `bulk-actions.execute`).
     */
    private function bulkModule(): string
    {
        return match ($this->model) {
            Vehicle::class => 'vehicles',
            TransportDriver::class => 'transport_drivers',
            TransportRoute::class => 'transport_routes',
            TransportStop::class => 'transport_stops',
            default => Str::snake(class_basename($this->model)),
        };
    }

    /**
     * The view permission that gates this master's bulk export button.
     */
    private function bulkPermission(): string
    {
        return match ($this->model) {
            Vehicle::class => 'vehicles.view',
            TransportDriver::class => 'transport_drivers.view',
            TransportRoute::class, TransportStop::class => 'transport_routes.view',
            default => Str::snake(class_basename($this->model)).'.view',
        };
    }

    /**
     * CSV export of a bulk selection from the master listing.
     *
     * Ids are treated as a request, never as data (normalised, capped,
     * re-queried inside the active college through the model's college scope)
     * and the master's view permission is re-checked here. The columns are
     * the ones the listing shows (long free-text fields excluded); for
     * drivers the linked staff member is resolved instead of the raw id, and
     * the license number — a government identity document number — is never
     * exported. Nothing is written.
     */
    public function export(Request $request, AuditLogService $audit): StreamedResponse
    {
        $this->authorize('viewAny', $this->model);

        $ids = ListSelection::ids($request->input('ids', []));

        $query = $this->model::query()->whereIn((new $this->model)->qualifyColumn('id'), $ids);
        if ($this->model === TransportDriver::class) {
            $query->with('faculty');
        }
        if ($this->model === TransportStop::class) {
            $query->with('route:id,name,code');
        }

        $order = isset($this->model::FIELDS['name']) ? 'name' : array_key_first($this->model::FIELDS);
        $records = $query->orderBy($order)->orderBy('id')->get();

        $fields = array_filter(
            array_keys($this->model::FIELDS),
            fn (string $field): bool => ! in_array($field, ['remarks', 'description', 'landmark', 'license_number'], true)
        );

        $headers = [$this->model === TransportStop::class ? 'Route' : null];
        foreach ($fields as $field) {
            $headers[] = $field === 'faculty_id' ? 'Staff' : Str::headline($field);
        }
        $headers = array_values(array_filter($headers, fn ($header) => $header !== null));

        $rows = $records->map(function ($record) use ($fields): array {
            $row = [];
            if ($record instanceof TransportStop) {
                $row[] = $record->route?->name;
            }
            foreach ($fields as $field) {
                $row[] = $field === 'faculty_id'
                    ? ($record->faculty?->full_name ?? 'Archived staff')
                    : $record->{$field};
            }

            return $row;
        });

        $audit->record(str_replace('-', '_', $this->routeName).'.exported', null, [], [
            'selected_ids' => count($ids),
            'rows' => $records->count(),
        ]);

        return CsvStreamExport::make($this->routeName.'-export-'.now()->format('Y-m-d').'.csv')
            ->withHeaders($headers)
            ->streamFromCollection($rows);
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
