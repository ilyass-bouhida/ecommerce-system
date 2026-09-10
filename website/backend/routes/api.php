<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\IntegrationLogController;
use App\Http\Controllers\WebhookEventController;
use App\Http\Controllers\WebsiteConnectionController;
use Illuminate\Support\Facades\Route;

// Public service health (real DB check).
Route::get('/v1/health', [HealthController::class, 'show']);

// OWNER-only management (Auth introspection + role check).
Route::middleware(['auth.introspect', 'owner'])->group(function () {
    Route::get('/v1/integrations/website', [WebsiteConnectionController::class, 'show']);
    Route::patch('/v1/integrations/website', [WebsiteConnectionController::class, 'update']);
    Route::post('/v1/integrations/website/test', [WebsiteConnectionController::class, 'test']);
    Route::get('/v1/integrations/website/logs', [IntegrationLogController::class, 'index']);
    Route::get('/v1/integrations/website/events', [WebhookEventController::class, 'index']);
    Route::get('/v1/integrations/website/events/{event}', [WebhookEventController::class, 'show']);
});
