<?php

namespace App\Http\Controllers\Transport;

use App\Models\Vehicle;

class VehicleController extends TransportMasterController
{
    public string $model = Vehicle::class;
    public string $title = 'Vehicles';
    public string $routeName = 'vehicles';
}
