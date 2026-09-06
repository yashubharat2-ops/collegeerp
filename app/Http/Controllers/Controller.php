<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Controllers authorize resource access through policies. Without this trait
     * every `$this->authorize(...)` call in a child controller raises an
     * "undefined method" Error, which surfaces as a 500 and silently skips the
     * authorization boundary entirely.
     */
    use AuthorizesRequests;
}
