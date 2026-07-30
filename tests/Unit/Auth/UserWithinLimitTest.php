<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;

it('never limits in the core (OSS) edition — the SaaS user model is the only override', function (): void {
    $user = new User;

    expect($user->withinLimit('max_clients', 999999))->toBeTrue()
        ->and($user->withinLimit('max_orders', 999999))->toBeTrue();
});
