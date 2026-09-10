<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Public inbound webhook. Secured by timestamp + HMAC signature in
// controller logic, NOT by user login. No browser auth required.
Route::post('/v1/website', [WebhookController::class, 'handle']);
