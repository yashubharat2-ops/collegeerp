<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Services\InventoryAssignmentService;
use App\Domain\Inventory\Support\InventoryFormOptions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryAssignmentRequest;
use App\Models\InventoryAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Asset Assignment (Inventory / Asset Management, Phase 3).
 *
 * Individual assets (the `item_type = 'asset'` rows of the existing
 * Items / Assets master) are lent to students and staff here. Assignment is
 * custody, not consumption: no stock movement is written — the assignment
 * rows ARE the asset's custody history. An asset has at most one active
 * assignment at a time; returning it (Asset Return module) preserves the
 * row, and a later re-assignment is a new row.
 *
 * Reuses the existing Items / Assets master — no duplicate asset entity.
 * Tenant isolation via CollegeScope + composite foreign key.
 */
class InventoryAssignmentController extends Controller
{
    public function __construct(private readonly InventoryAssignmentService $assignments)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', InventoryAssignment::class);

        $query = InventoryAssignment::query()
            ->with(['item:id,name,code,serial_number', 'assignee', 'returner:id,name', 'creator:id,name'])
            ->orderByDesc('assigned_on')
            ->orderByDesc('id');

        if ($request->filled('item_id')) {
            $query->where('item_id', (int) $request->input('item_id'));
        }

        if (in_array($request->input('status'), InventoryAssignment::STATUSES, true)) {
            $query->where('status', $request->input('status'));
        }

        if (in_array($request->input('assigned_to_type'), InventoryAssignment::ASSIGNEE_TYPES, true)) {
            $query->where('assigned_to_type', $request->input('assigned_to_type'));
        }

        return view('inventory_assignments.index', [
            'assignments' => $query->paginate(20)->withQueryString(),
            'items' => InventoryFormOptions::assets(),
            'statuses' => InventoryAssignment::STATUSES,
            'assigneeTypes' => InventoryAssignment::ASSIGNEE_TYPES,
            'filters' => [
                'item_id' => $request->input('item_id'),
                'status' => $request->input('status'),
                'assigned_to_type' => $request->input('assigned_to_type'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', InventoryAssignment::class);

        return view('inventory_assignments.create', [
            'items' => InventoryFormOptions::assets(),
            'students' => InventoryFormOptions::students(),
            'faculties' => InventoryFormOptions::faculties(),
            'selectedItem' => $request->filled('item_id') ? (int) $request->input('item_id') : null,
        ]);
    }

    public function store(StoreInventoryAssignmentRequest $request): RedirectResponse
    {
        $assignment = $this->assignments->create(
            app(\App\Support\Tenancy\TenantContext::class)->require(),
            $request->validated(),
            $request->user(),
        );

        return redirect()
            ->route('inventory-assignments.index', ['item_id' => $assignment->item_id])
            ->with('success', "\"{$assignment->item?->name}\" assigned to {$assignment->assigneeName()} since {$assignment->assigned_on->format('d M Y')}.");
    }
}
