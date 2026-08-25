<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation\Support;

use App\Modules\Auth\Application\Contracts\AccountRepresentation;
use App\Modules\Auth\Presentation\Resources\UserResource;
use App\Modules\Shared\Domain\Contracts\Account;
use Illuminate\Database\Eloquent\Model;

/**
 * Renders the account through the resource Auth's own endpoints use, so the
 * three bodies cannot say different things.
 */
final readonly class UserResourceRepresentation implements AccountRepresentation
{
    public function forAccount(Account&Model $account): array
    {
        return UserResource::make($account)->resolve();
    }
}
