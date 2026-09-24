<?php

namespace App\Domain\Hostel\Support;

use App\Models\Hostel;
use App\Models\HostelBuilding;
use App\Models\HostelRoom;

/**
 * HostelFormOptions — the select lists the Hostel Management screens share.
 *
 * Every list is read through the tenant-scoped models (CollegeScope), so a
 * controller can only ever offer the active college's masters. Keeping the
 * option lists in one place stops the screens from drifting apart and keeps
 * the controllers thin.
 */
final class HostelFormOptions
{
    /**
     * Hostels a building can be created under.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Hostel>
     */
    public static function hostels(): mixed
    {
        return Hostel::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'code', 'status']);
    }

    /**
     * Buildings (with their hostel) a room can be created under, or used as
     * an index filter.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, HostelBuilding>
     */
    public static function buildings(): mixed
    {
        return HostelBuilding::query()
            ->with('hostel:id,name')
            ->orderBy('hostel_id')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'hostel_id', 'name', 'code', 'status']);
    }

    /**
     * Rooms (with their building and hostel) a bed can be created under, or
     * used as an index filter.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, HostelRoom>
     */
    public static function rooms(): mixed
    {
        return HostelRoom::query()
            ->with(['building:id,hostel_id,name', 'hostel:id,name'])
            ->orderBy('hostel_id')
            ->orderBy('building_id')
            ->orderBy('room_number')
            ->orderBy('id')
            ->get(['id', 'hostel_id', 'building_id', 'room_number', 'status']);
    }

    /**
     * A display label like "North Block (Boys Hostel)" for an option.
     */
    public static function buildingLabel(HostelBuilding $building): string
    {
        return $building->name.' ('.($building->hostel?->name ?? '—').')';
    }

    /**
     * A display label like "101 — North Block (Boys Hostel)" for an option.
     */
    public static function roomLabel(HostelRoom $room): string
    {
        return $room->room_number.' — '.($room->building?->name ?? '—').' ('.($room->hostel?->name ?? '—').')';
    }
}
