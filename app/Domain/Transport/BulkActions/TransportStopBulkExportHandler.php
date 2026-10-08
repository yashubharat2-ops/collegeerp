<?php

namespace App\Domain\Transport\BulkActions;

use App\Models\TransportStop;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected stops (Transport — Stops).
 *
 * The Stops navigation entry is a flat, cross-route listing and the per-route
 * stops page shows the same TransportStop records scoped to one route; both
 * listings share this one action. Each ticked stop is re-queried inside the
 * active college and re-authorized through {@see \App\Policies\TransportStopPolicy}
 * (`transport_routes.view`, the stops permission family). The CSV carries the
 * route, name, code, sequence, pickup / drop times, landmark and status — the
 * columns the listing shows. An export never re-sequences, renames or deletes
 * a stop.
 */
class TransportStopBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return TransportStop::class;
    }

    public function requiredPermission(): ?string
    {
        return 'transport_routes.view';
    }

    public function policyAbility(): ?string
    {
        return 'view';
    }

    protected function exportRouteName(): string
    {
        return 'transport-stops.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'stop' : 'stops';
    }
}
