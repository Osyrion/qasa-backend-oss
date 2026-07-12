<?php

declare(strict_types=1);

use App\Modules\Auth\Presentation\Controllers\AuthController;
use App\Modules\Auth\Presentation\Controllers\DashboardController;
use App\Modules\Auth\Presentation\Controllers\EmailVerificationController;
use App\Modules\Auth\Presentation\Controllers\GoogleAuthController;
use App\Modules\Auth\Presentation\Controllers\PasswordResetController;
use App\Modules\Auth\Presentation\Controllers\PersonalAccessTokenController;
use App\Modules\Auth\Presentation\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function (): void {
    // Public routes — throttled against brute force and enumeration
    Route::middleware('throttle:10,1')->group(function (): void {
        Route::post('auth/register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
        Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
        Route::post('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
        Route::post('auth/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('auth.password.email');
        Route::post('auth/reset-password', [PasswordResetController::class, 'reset'])->name('auth.password.reset');
        Route::post('auth/2fa/verify', [TwoFactorController::class, 'verify'])->name('auth.2fa.verify');
    });

    // Signed link from the verification email — no auth, the signature is the proof
    Route::get('auth/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:10,1'])
        ->name('verification.verify');

    // Protected routes
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::put('auth/profile', [AuthController::class, 'updateProfile'])->name('auth.profile.update');
        Route::post('auth/profile/logo', [AuthController::class, 'uploadLogo'])->name('auth.profile.logo');
        Route::get('profile/export', [AuthController::class, 'exportData'])->name('auth.profile.export');
        Route::delete('profile', [AuthController::class, 'deleteAccount'])->name('auth.profile.delete');
        Route::post('auth/email/verification-notification', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:6,1')
            ->name('verification.send');

        Route::get('auth/tokens', [PersonalAccessTokenController::class, 'index'])->name('auth.tokens.index');
        Route::post('auth/tokens', [PersonalAccessTokenController::class, 'store'])->name('auth.tokens.store');
        Route::delete('auth/tokens/{id}', [PersonalAccessTokenController::class, 'destroy'])->name('auth.tokens.destroy');

        Route::post('auth/2fa/enable', [TwoFactorController::class, 'enable'])->name('auth.2fa.enable');
        Route::post('auth/2fa/confirm', [TwoFactorController::class, 'confirm'])->name('auth.2fa.confirm');
        Route::delete('auth/2fa', [TwoFactorController::class, 'disable'])->name('auth.2fa.disable');
        Route::post('auth/2fa/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('auth.2fa.recovery-codes');
    });
});
