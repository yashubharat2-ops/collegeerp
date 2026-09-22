<?php

namespace App\Http\Controllers\Transport;

use App\Models\TransportStop;

class TransportStopController extends TransportMasterController
{
    public string $model = TransportStop::class;
    public string $title = 'Stops';
    public string $routeName = 'transport-stops';
}
