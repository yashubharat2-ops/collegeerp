<?php

namespace App\Domain\Transport\BulkActions;

use App\Models\TransportRoute;
use App\Support\BulkAction\BulkExportHandler;

/**
 * Bulk export of selected transport routes (Transport — Routes).
 *
 * Each ticked route is re-queried inside the active college and
 * re-authorized through {@see \App\Policies\TransportRoutePolicy}
 * (`transport_routes.view`). The CSV carries the name, code and status — the
 * columns the listing shows. Stops hang off the route and are exported from
 * their own listing; an export never renames, deactivates or deletes a route.
 */
class TransportRouteBulkExportHandler extends BulkExportHandler
{
    public function modelClass(): string
    {
        return TransportRoute::class;
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
        return 'transport-routes.export';
    }

    protected function recordNoun(int $count): string
    {
        return $count === 1 ? 'route' : 'routes';
    }
}
