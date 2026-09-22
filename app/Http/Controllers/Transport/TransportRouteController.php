<?php

namespace App\Http\Controllers\Transport;

use App\Models\TransportRoute;

class TransportRouteController extends TransportMasterController
{
    public string $model = TransportRoute::class;
    public string $title = 'Routes';
    public string $routeName = 'transport-routes';
}
