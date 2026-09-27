<?php

namespace App\Domain\Inventory\Services;

use App\Models\InventoryStockMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Period opening, movement and closing figures calculated only from the ledger. */
class InventoryStockReportService
{
    /**
     * Dates are movement dates (not posting dates). A backdated correction is
     * reflected in its date's period. Never sum quantities across different
     * items/units: each row reports a single item's own unit.
     *
     * @param  array{from?: string|null, to?: string|null, item_id?: int|null}  $filters
     * @return LengthAwarePaginator<InventoryStockMovement>
     */
    public function activity(array $filters): LengthAwarePaginator
    {
        $from = $filters['from'] ?? null;
        $query = InventoryStockMovement::query()
            ->select('item_id')
            ->with(['item' => fn ($q) => $q->withTrashed()->select('id', 'college_id', 'name', 'code', 'unit', 'deleted_at')]);

        if (! empty($filters['to'])) {
            $query->where('movement_date', '<=', $filters['to']);
        }

        if (! empty($filters['item_id'])) {
            $query->where('item_id', $filters['item_id']);
        }

        $query->selectRaw("SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) AS closing");

        if ($from) {
            $query->selectRaw("SUM(CASE WHEN movement_date < ? THEN CASE WHEN direction = 'in' THEN quantity ELSE -quantity END ELSE 0 END) AS opening", [$from]);
        } else {
            $query->selectRaw('0 AS opening');
        }

        $period = $from ? 'movement_date >= ? AND ' : '';
        $bindings = $from ? [$from] : [];

        $query->selectRaw("SUM(CASE WHEN {$period}direction = 'in' THEN quantity ELSE 0 END) AS received", $bindings)
            ->selectRaw("SUM(CASE WHEN {$period}direction = 'out' THEN quantity ELSE 0 END) AS issued", $bindings)
            ->selectRaw($from ? 'SUM(CASE WHEN movement_date >= ? THEN 1 ELSE 0 END) AS transactions' : 'COUNT(*) AS transactions', $bindings);

        return $query->groupBy('item_id')->orderBy('item_id')->paginate(20)->withQueryString();
    }
}
