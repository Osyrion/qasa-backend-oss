<?php

declare(strict_types=1);

use App\Modules\Shared\Presentation\Controllers\DeepHealthController;
use Illuminate\Support\Facades\Route;

Route::get('up/deep', DeepHealthController::class)
    ->middleware('auth:sanctum')
    ->name('health.deep');
