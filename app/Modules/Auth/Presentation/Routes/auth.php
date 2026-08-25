<?php

declare(strict_types=1);

use App\Modules\Auth\Presentation\Controllers\AuthController;
use App\Modules\Auth\Presentation\Controllers\DashboardController;
use App\Modules\Auth\Presentation\Controllers\EmailVerificationController;
use App\Modules\Auth\Presentation\Controllers\GoogleAuthController;
use App\Modules\Auth\Presentation\Controllers\PasswordResetController;
use App\Modules\Auth\Presentation\Controllers\PersonalAccessTokenController;
use App\Modules\Auth\Presentation\Controllers\PhoneVerificationController;
use App\Modules\Auth\Presentation\Controllers\SessionController;
use App\Modules\Auth\Presentation\Controllers\SetupStatusController;
use App\Modules\Auth\Presentation\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function (): void {
    // Public routes — throttled against brute force and enumeration
    Route::middleware('throttle:10,1')->group(function (): void {
        // Its own limiter rather than the group's: registration is the one
        // endpoint here that creates state, and a trial with it, so it is
        // capped harder than the read-mostly siblings it sits next to.
        Route::post('auth/register', [AuthController::class, 'register'])
            ->middleware('throttle:register')
            ->name('auth.register');
        // Extra per-account limiter on top of the group's per-IP throttle,
        // so distributed credential stuffing against one account is capped.
        Route::post('auth/login', [AuthController::class, 'login'])
            ->middleware('throttle:auth-login')
            ->name('auth.login');
        Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
        Route::post('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
        // Google's redirect target for the mobile flow — see the controller.
        Route::get('auth/google/callback/mobile', [GoogleAuthController::class, 'mobileCallbackBridge'])
            ->name('auth.google.callback.mobile');
        Route::post('auth/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('auth.password.email');
        Route::post('auth/reset-password', [PasswordResetController::class, 'reset'])->name('auth.password.reset');
        // Completes a login stuck at the 2FA challenge — the caller holds a
        // short-lived challenge token, not a bearer token, so this stays public.
        Route::post('auth/2fa/verify', [TwoFactorController::class, 'verify'])->name('auth.2fa.verify');
    });

    // Signed link from the verification email — no auth, the signature is the proof
    Route::get('auth/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:10,1'])
        ->name('verification.verify');

    // Protected routes
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::put('auth/profile', [AuthController::class, 'updateProfile'])->name('auth.profile.update');
        Route::post('auth/profile/logo', [AuthController::class, 'uploadLogo'])->name('auth.profile.logo');
        Route::get('profile/export', [AuthController::class, 'exportData'])->name('auth.profile.export');
        Route::get('profile/setup-status', [SetupStatusController::class, 'index'])->name('auth.profile.setup-status');
        Route::post('profile/accept-terms', [AuthController::class, 'acceptTerms'])->name('auth.profile.accept-terms');
        Route::delete('profile', [AuthController::class, 'deleteAccount'])->name('auth.profile.delete');
        Route::post('auth/email/verification-notification', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:6,1')
            ->name('verification.send');

        // Scoped personal access tokens for third-party integrations
        Route::get('auth/tokens', [PersonalAccessTokenController::class, 'index'])->name('auth.tokens.index');
        Route::post('auth/tokens', [PersonalAccessTokenController::class, 'store'])
            ->middleware('feature:api_access')
            ->name('auth.tokens.store');
        Route::delete('auth/tokens/{id}', [PersonalAccessTokenController::class, 'destroy'])->name('auth.tokens.destroy');

        // "My devices" — login sessions, kept separate from the integration
        // tokens above (see SessionController's own docblock for why).
        Route::get('auth/sessions', [SessionController::class, 'index'])->name('auth.sessions.index');
        Route::delete('auth/sessions', [SessionController::class, 'destroyOthers'])->name('auth.sessions.destroy-others');
        Route::delete('auth/sessions/{id}', [SessionController::class, 'destroy'])->name('auth.sessions.destroy');
        Route::put('auth/push-token', [SessionController::class, 'updatePushToken'])->name('auth.push-token.update');

        // Phone verification. Authenticated because it is a step *after*
        // registration, never a condition of it — an SMS gateway outage must
        // not be able to stop an account being created.
        Route::post('auth/phone/send-code', [PhoneVerificationController::class, 'sendCode'])
            ->middleware('throttle:phone-send')
            ->name('auth.phone.send-code');
        Route::post('auth/phone/verify', [PhoneVerificationController::class, 'verify'])
            ->middleware('throttle:phone-verify')
            ->name('auth.phone.verify');

        // Two-factor authentication management
        Route::post('auth/2fa/enable', [TwoFactorController::class, 'enable'])->name('auth.2fa.enable');
        Route::post('auth/2fa/confirm', [TwoFactorController::class, 'confirm'])->name('auth.2fa.confirm');
        Route::delete('auth/2fa', [TwoFactorController::class, 'disable'])->name('auth.2fa.disable');
        Route::post('auth/2fa/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('auth.2fa.recovery-codes');
    });
});
