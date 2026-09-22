<?php

namespace App\Http\Controllers\Transport;

use App\Domain\Transport\Services\VehicleDocumentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transport\StoreVehicleDocumentRequest;
use App\Http\Requests\Transport\UpdateVehicleDocumentRequest;
use App\Models\{Vehicle, VehicleDocument};
use App\Services\Audit\AuditLogService;
use App\Services\Files\SecureFileService;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Vehicle Documents (Transport Phase 2).
 *
 * Secure upload / download / archive for documents of EXISTING vehicles.
 * Every access path re-resolves the document through CollegeScope (a foreign
 * college's id 404s) and authorizes before touching the file. The stored path
 * is server-generated and re-checked on read, and files live on the private
 * disk so they are never reachable by URL.
 */
class VehicleDocumentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', VehicleDocument::class);

        $query = VehicleDocument::query()
            ->with(['vehicle:id,college_id,registration_number', 'uploadedBy:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('document_type', 'like', "%{$search}%")
                    ->orWhere('document_number', 'like', "%{$search}%")
                    ->orWhere('original_filename', 'like', "%{$search}%")
                    ->orWhereHas('vehicle', function ($vq) use ($search): void {
                        $vq->where('registration_number', 'like', "%{$search}%");
                    });
            });
        }

        if ($vehicleId = $request->input('vehicle_id')) {
            $query->where('vehicle_id', $vehicleId);
        }

        if ($documentType = trim((string) $request->input('document_type'))) {
            $query->where('document_type', $documentType);
        }

        if (($status = (string) $request->input('document_status')) !== '') {
            $today = now()->toDateString();
            $soon = now()->addDays(VehicleDocument::EXPIRING_SOON_DAYS)->toDateString();
            $query->whereNotNull('expiry_date')
                ->when($status === VehicleDocument::STATUS_EXPIRED, fn ($q) => $q->where('expiry_date', '<', $today))
                ->when($status === VehicleDocument::STATUS_EXPIRING, fn ($q) => $q->whereBetween('expiry_date', [$today, $soon]))
                ->when($status === VehicleDocument::STATUS_ACTIVE, fn ($q) => $q->where('expiry_date', '>', $soon));
        }

        return view('transport.vehicle_documents.index', [
            'documents' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'vehicle_id' => $request->input('vehicle_id'),
            'document_type' => $request->input('document_type'),
            'document_status' => $request->input('document_status'),
            'vehicles' => $this->vehicleOptions(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', VehicleDocument::class);

        return view('transport.vehicle_documents.create', [
            'vehicles' => $this->vehicleOptions(),
            'selectedVehicleId' => $request->input('vehicle_id'),
        ]);
    }

    public function store(StoreVehicleDocumentRequest $request, VehicleDocumentService $service): RedirectResponse
    {
        $document = $service->store(
            $request->validated(),
            $request->file('file'),
            (int) app(\App\Support\Tenancy\TenantContext::class)->id(),
            auth()->id(),
        );

        return redirect()->route('vehicle-documents.index')
            ->with('success', 'Document uploaded for '.$document->vehicle->registration_number.'.');
    }

    public function edit(string $vehicle_document): View
    {
        $model = $this->findScoped($vehicle_document);
        $this->authorize('update', $model);

        return view('transport.vehicle_documents.edit', [
            'document' => $model->load(['vehicle:id,college_id,registration_number']),
            'vehicles' => $this->vehicleOptions(),
        ]);
    }

    public function update(UpdateVehicleDocumentRequest $request, string $vehicle_document, VehicleDocumentService $service): RedirectResponse
    {
        $model = $this->findScoped($vehicle_document);

        if ($request->hasFile('file')) {
            $service->reupload($model, $request->file('file'), auth()->id());
        }

        $service->updateMetadata($model, $request->validated(), auth()->id());

        return redirect()->route('vehicle-documents.index')->with('success', 'Document updated.');
    }

    public function destroy(string $vehicle_document, VehicleDocumentService $service): RedirectResponse
    {
        $model = $this->findScoped($vehicle_document);
        $this->authorize('delete', $model);

        $service->delete($model, auth()->id());

        return redirect()->route('vehicle-documents.index')->with('success', 'Document deleted.');
    }

    /**
     * Stream a document from the private disk.
     *
     * Authorization first, then a path sanity check (defence in depth on a
     * server-generated value), then an existence check. The download is audited
     * without ever logging file content.
     */
    public function download(string $vehicle_document, SecureFileService $files, AuditLogService $audit): StreamedResponse
    {
        $model = $this->findScoped($vehicle_document);
        $this->authorize('download', $model);

        $path = VehicleDocumentService::assertSafePath($model->file_path, (int) $model->college_id, (int) $model->vehicle_id);

        if (! Storage::disk('private')->exists($path)) {
            abort(404, 'The stored file is no longer available.');
        }

        $audit->record('vehicle_document.downloaded', $model, [], [
            'id' => $model->id,
            'vehicle_id' => $model->vehicle_id,
            'document_type' => $model->document_type,
            'file_path' => $path,
        ]);

        return $files->download($path, VehicleDocumentService::safeDownloadName($model->original_filename));
    }

    /** Explicit scoped resolution: a foreign college's id 404s. */
    private function findScoped(string $id): VehicleDocument
    {
        return VehicleDocument::query()->findOrFail($id);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Vehicle> */
    private function vehicleOptions()
    {
        return Vehicle::query()->orderBy('registration_number')->get(['id', 'college_id', 'registration_number']);
    }
}
