<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Contracts;

use App\Modules\Shared\Domain\Contracts\Account;
use Illuminate\Database\Eloquent\Model;

/**
 * The canonical JSON body for an account — the same one `GET /api/v1/auth/me`
 * returns, and the shape documented as `#/components/schemas/User`.
 *
 * Three endpoints hand an account back: login/registration (Auth), completing
 * tax residency (Taxation) and accepting a team invitation (Team). Two of them
 * were reaching into `Auth\Presentation\Resources\UserResource` to do it, which
 * is a boundary violation *and* the reason the three could drift: a field added
 * to the resource reaches all three only because they happen to share a class,
 * not because anything says they must.
 *
 * The shape is the contract; the resource is one implementation of it. That is
 * why this interface lives in Application while what satisfies it lives in
 * Presentation — rendering is a presentation concern, agreeing on what is
 * rendered is not.
 */
interface AccountRepresentation
{
    /**
     * @return array<string, mixed>
     */
    public function forAccount(Account&Model $account): array;
}
