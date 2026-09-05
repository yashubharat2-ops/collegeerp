<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::get('/health', fn () => response()->json(['status' => 'ok', 'version' => 'v1']))->name('api.v1.health');
    Route::middleware(['auth:web', 'tenant', 'tenant.access'])->get('/me', fn (\Illuminate\Http\Request $request) => response()->json(['data' => $request->user()->only(['id', 'name', 'email'])]))->name('api.v1.me');
});
