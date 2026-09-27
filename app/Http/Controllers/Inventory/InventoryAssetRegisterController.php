<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Support\InventoryFormOptions;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryMaintenance;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryAssetRegisterController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewRegister', InventoryItem::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(InventoryItem::STATUSES)],
            'custody' => ['nullable', Rule::in(['assigned', 'unassigned'])],
        ]);

        $query = InventoryItem::query()
            ->where('item_type', InventoryItem::TYPE_ASSET)
            ->with(['category:id,name,code', 'activeAssignment.assignee'])
            ->withMax('assignments as last_returned_on', 'returned_on')
            ->withMax(['maintenances as last_service_on' => fn ($q) => $q->where('status', InventoryMaintenance::STATUS_COMPLETED)], 'completed_on')
            ->withCount(['maintenances as open_maintenance_count' => fn ($q) => $q->where('status', '!=', InventoryMaintenance::STATUS_COMPLETED)]);

        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (($filters['custody'] ?? null) === 'assigned') {
            $query->whereHas('assignments', fn ($q) => $q->active());
        } elseif (($filters['custody'] ?? null) === 'unassigned') {
            $query->whereDoesntHave('assignments', fn ($q) => $q->active());
        }

        return view('inventory_asset_register.index', [
            'assets' => $query->orderBy('name')->orderBy('id')->paginate(20)->withQueryString(),
            'categories' => InventoryFormOptions::categories(),
            'statuses' => InventoryItem::STATUSES,
            'filters' => array_merge(['category_id' => null, 'status' => null, 'custody' => null], $filters, ['search' => $search]),
        ]);
    }
}
