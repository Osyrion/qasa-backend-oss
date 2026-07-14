<?php

declare(strict_types=1);

use App\Modules\Shared\Authorization\AbilityCatalog;

it('contains only core abilities — no team, billing or admin permissions', function () {
    foreach (AbilityCatalog::abilities() as $ability) {
        expect($ability)->not->toStartWith('team.')
            ->not->toStartWith('billing.')
            ->not->toStartWith('admin.');
    }
});

it('handles exactly the catalogued abilities', function () {
    foreach (AbilityCatalog::abilities() as $ability) {
        expect(AbilityCatalog::handles($ability))->toBeTrue();
    }

    expect(AbilityCatalog::handles('team.manage'))->toBeFalse()
        ->and(AbilityCatalog::handles('nonsense'))->toBeFalse();
});
