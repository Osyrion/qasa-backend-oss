<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Persistence;

use App\Modules\Shared\Domain\Contracts\AccountLocator;
use App\Modules\Shared\Domain\Contracts\AccountMemberDirectory;

/**
 * The core edition's answer: an account is one person.
 *
 * Not a null object — this is correct, not a stub. Teams are a SaaS feature
 * and `owner_id` is a SaaS column, so there is nobody else to list.
 */
final readonly class OwnerOnlyMemberDirectory implements AccountMemberDirectory
{
    public function __construct(
        private AccountLocator $accounts,
    ) {}

    public function emails(string $accountId): array
    {
        $owner = $this->accounts->find($accountId);

        return $owner === null ? [] : [strtolower($owner->supplierProfile()->email)];
    }
}
