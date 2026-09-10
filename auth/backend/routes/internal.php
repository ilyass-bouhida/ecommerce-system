<?php

use App\Http\Controllers\IntrospectController;
use Illuminate\Support\Facades\Route;

// Sibling-microservice identity check. Protected by X-Internal-Key
// (application logic), NOT by user authentication.
Route::post('/v1/auth/introspect', [IntrospectController::class, 'introspect']);
