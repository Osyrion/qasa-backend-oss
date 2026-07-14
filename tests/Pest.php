<?php

declare(strict_types=1);

use App\Modules\Auth\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Optional edition overlay — forces env before the app boots.
if (file_exists(__DIR__.'/Pest.edition.php')) {
    require __DIR__.'/Pest.edition.php';
}

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit');

/**
 * The edition's User model class (auth provider config).
 *
 * @return class-string<User>
 */
function userModel(): string
{
    /** @var class-string<User> $model */
    $model = config('auth.providers.users.model');

    return $model;
}

/**
 * Creates a user of the edition's User model.
 *
 * @param  array<string, mixed>  $attributes
 */
function createUser(array $attributes = []): User
{
    $user = userModel()::factory()->create($attributes);

    // Factory path bypasses RegisterUserAction — an edition overlay can
    // mirror here whatever its registration listeners would do.
    if (function_exists('grantOwnerRole')) {
        grantOwnerRole($user);
    }

    return $user;
}
