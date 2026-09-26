<?php

namespace App\Domain\Inventory\Support;

use App\Models\Faculty;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryVendor;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * Shared, tenant-scoped option lists for inventory forms and filters.
 *
 * Every query goes through the CollegeScope, so a foreign college's categories
 * can never appear in a dropdown.
 */
class InventoryFormOptions
{
    /**
     * @return Collection<int, InventoryCategory>
     */
    public static function categories(): Collection
    {
        return InventoryCategory::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'status']);
    }

    /**
     * Vendors for the purchase order forms and filters.
     *
     * @return Collection<int, InventoryVendor>
     */
    public static function vendors(): Collection
    {
        return InventoryVendor::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'status']);
    }

    /**
     * Items / assets for the purchase order and stock screens.
     *
     * Inactive items are still listed (a dormant catalogue entry may have to be
     * received or written off); the forms flag them the way the category
     * dropdown does.
     *
     * @return Collection<int, InventoryItem>
     */
    public static function items(): Collection
    {
        return InventoryItem::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'unit', 'item_type', 'quantity', 'status']);
    }

    /**
     * Active consumables for the Item Issue / Allocation screen (Phase 3).
     *
     * @return Collection<int, InventoryItem>
     */
    public static function consumables(): Collection
    {
        return InventoryItem::query()
            ->where('item_type', InventoryItem::TYPE_CONSUMABLE)
            ->where('status', InventoryItem::STATUS_ACTIVE)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'unit', 'item_type', 'quantity', 'status']);
    }

    /**
     * Assets for the assignment / return / maintenance screens (Phase 3).
     * Inactive assets stay listed — their history must remain reachable.
     *
     * @return Collection<int, InventoryItem>
     */
    public static function assets(): Collection
    {
        return InventoryItem::query()
            ->where('item_type', InventoryItem::TYPE_ASSET)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'serial_number', 'status']);
    }

    /**
     * Active students of the active college for the recipient / assignee
     * dropdowns (Phase 3).
     *
     * @return Collection<int, Student>
     */
    public static function students(): Collection
    {
        return Student::query()
            ->where('status', 'active')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id')
            ->get(['id', 'student_number', 'first_name', 'middle_name', 'last_name', 'status']);
    }

    /**
     * Active staff of the active college for the recipient / assignee
     * dropdowns (Phase 3). Staff are the `faculties` table, which also
     * backs the HR Employee alias.
     *
     * @return Collection<int, Faculty>
     */
    public static function faculties(): Collection
    {
        return Faculty::query()
            ->where('status', 'active')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get(['id', 'employee_code', 'first_name', 'middle_name', 'last_name', 'status']);
    }
}
