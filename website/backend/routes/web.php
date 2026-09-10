<?php

use Illuminate\Support\Facades\Route;

// API-focused service: root returns minimal service info, no Blade, no
// session-dependent behavior.
Route::get('/', function () {
    return response()->json(['service' => 'website', 'status' => 'ok']);
});
