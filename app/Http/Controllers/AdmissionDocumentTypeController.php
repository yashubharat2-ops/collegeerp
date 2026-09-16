<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdmissionDocumentType\StoreAdmissionDocumentTypeRequest;
use App\Http\Requests\AdmissionDocumentType\UpdateAdmissionDocumentTypeRequest;
use App\Models\AdmissionDocumentType;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdmissionDocumentTypeController extends Controller
{
    private const AUDITED = ['id','code','name','description','is_required','allowed_extensions','allowed_mimes','max_size_kb','status'];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AdmissionDocumentType::class);

        $query = AdmissionDocumentType::query()
            ->orderBy('name')
            ->orderBy('id');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if (in_array($request->input('status'), ['active','inactive'], true)) {
            $query->where('status', $request->input('status'));
        }

        return view('admission_document_types.index', [
            'documentTypes' => $query->paginate(15)->withQueryString(),
            'search' => trim((string) $request->input('search')),
            'status' => $request->input('status'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', AdmissionDocumentType::class);

        return view('admission_document_types.create');
    }

    public function store(StoreAdmissionDocumentTypeRequest $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validated();
        $data['is_required'] = (bool) ($data['is_required'] ?? false);

        $type = AdmissionDocumentType::create($data);

        $audit->record('admission_document_type.created', $type, [], $type->only(self::AUDITED));

        return redirect()->route('admission-document-types.index')->with('success', 'Document type '.$type->name.' created.');
    }

    public function edit(string $admission_document_type): View
    {
        $model = $this->findScoped($admission_document_type);
        $this->authorize('update', $model);

        return view('admission_document_types.edit', [
            'documentType' => $model,
        ]);
    }

    public function update(UpdateAdmissionDocumentTypeRequest $request, string $admission_document_type, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($admission_document_type);
        $old = $model->only(self::AUDITED);

        $data = $request->validated();
        $data['is_required'] = (bool) ($data['is_required'] ?? false);

        $model->update($data);

        $audit->record('admission_document_type.updated', $model, $old, $model->only(self::AUDITED));

        return back()->with('success', 'Document type updated.');
    }

    public function destroy(string $admission_document_type, AuditLogService $audit): RedirectResponse
    {
        $model = $this->findScoped($admission_document_type);
        $this->authorize('delete', $model);

        $snapshot = $model->only(self::AUDITED);
        $model->delete();
        $audit->record('admission_document_type.deleted', $model, $snapshot, []);

        return back()->with('success', 'Document type deleted.');
    }

    private function findScoped(string $id): AdmissionDocumentType
    {
        return AdmissionDocumentType::query()->findOrFail($id);
    }
}
