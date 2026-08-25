<?php

declare(strict_types=1);

use App\Modules\Shared\Presentation\Controllers\WaitlistController;
use Illuminate\Support\Facades\Route;

Route::post('api/v1/public/waitlist', [WaitlistController::class, 'store'])
    ->middleware('throttle:waitlist')
    ->name('waitlist.store');
