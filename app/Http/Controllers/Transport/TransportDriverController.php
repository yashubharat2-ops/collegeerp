<?php

namespace App\Http\Controllers\Transport;

use App\Models\TransportDriver;

class TransportDriverController extends TransportMasterController
{
    public string $model = TransportDriver::class;
    public string $title = 'Drivers';
    public string $routeName = 'transport-drivers';
}
