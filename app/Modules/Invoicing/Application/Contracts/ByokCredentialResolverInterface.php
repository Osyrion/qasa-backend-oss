<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Models\AiCredential;
use App\Modules\Shared\Domain\Contracts\Account;

/**
 * Which of an account's own AI credentials (if any) extraction should use,
 * in the configured provider order.
 *
 * Feature-gating (ai_byok) and the platform-key fallback are the caller's
 * concern, not this resolver's.
 */
interface ByokCredentialResolverInterface
{
    public function forOwner(Account $owner): ?AiCredential;
}
