<?php

namespace App\Domain\Hostel\BulkActions;

use App\Models\HostelRoom;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected rooms (Hostel — Rooms).
 *
 * Each ticked room is re-queried inside the active college and re-authorized
 * through {@see \App\Policies\HostelRoomPolicy} (`hostel_rooms.view`). The
 * CSV carries the room number, building, hostel, floor, type, bed count /
 * capacity and status — the columns the listing shows. An export never
 * renumbers, reclassifies or deactivates a room.
 */
class HostelRoomBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return HostelRoom::class;
    }

    public function requiredPermission(): ?string
    {
        return 'hostel_rooms.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'hostel-rooms.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'room' : 'rooms';
    }
}
