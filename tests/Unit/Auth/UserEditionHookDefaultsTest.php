<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;

/**
 * The core User answers every edition hook with the OSS default; the SaaS
 * User model is the only override. These are the defaults the generated core
 * ships with, and nothing in that edition exercises them — a premium module
 * is the only caller — so they are asserted here rather than discovered when
 * scripts/build-oss.sh produces an edition that fataled on a missing method.
 */
it('never limits in the core (OSS) edition — the SaaS user model is the only override', function (): void {
    $user = new User;

    expect($user->withinLimit('max_clients', 999999))->toBeTrue()
        ->and($user->withinLimit('max_orders', 999999))->toBeTrue();
});

it('reports every metered limit as unlimited in the core edition', function (): void {
    $user = new User;

    expect($user->planLimit('ai_extraction_monthly'))->toBe(-1)
        ->and($user->planLimit('anything_at_all'))->toBe(-1);
});

it('treats every core-edition user as the owner of their own account', function (): void {
    $user = new User;

    expect($user->isOwner())->toBeTrue();
});

it('offers no online payments in the core edition', function (): void {
    // Matches the AlwaysUnavailableOnlinePayment binding the core provider
    // installs: no Stripe Connect, so no plan can permit card payments.
    $user = new User;

    expect($user->allowsOnlinePayments())->toBeFalse();
});
