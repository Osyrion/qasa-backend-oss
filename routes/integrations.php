<?php

declare(strict_types=1);

use App\Modules\Integrations\Presentation\Controllers\WebhookEndpointController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['auth:sanctum', SubstituteBindings::class])->group(function (): void {
    Route::apiResource('webhook-endpoints', WebhookEndpointController::class)
        ->parameters(['webhook-endpoints' => 'webhook_endpoint']);

    Route::post('webhook-endpoints/{webhook_endpoint}/test', [WebhookEndpointController::class, 'test'])
        ->name('webhook-endpoints.test');

    Route::get('webhook-endpoints/{webhook_endpoint}/deliveries', [WebhookEndpointController::class, 'deliveries'])
        ->name('webhook-endpoints.deliveries');
});
