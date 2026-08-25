<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Persistence;

use App\Modules\Shared\Domain\Contracts\AccountLocator;
use App\Modules\Shared\Domain\Contracts\FullAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * The auth provider's model, resolved from config exactly as HasUserScope
 * resolves the `user` relation — same reason, same one line.
 */
final class ConfiguredAccountLocator implements AccountLocator
{
    public function find(string $accountId): (FullAccount&Model)|null
    {
        /** @var class-string<FullAccount&Model> $model */
        $model = config('auth.providers.users.model');

        /** @var (FullAccount&Model)|null */
        return $model::query()->find($accountId);
    }
}
