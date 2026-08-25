<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Application\DTOs\AiCompletionResult;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;

/**
 * Cross-module boundary for AiAssistantService — premium modules (Reports,
 * Automation) depend on this contract, never the concrete service, per
 * tests/Architecture/ModuleBoundariesTest.php (same pattern as
 * SettleProformaActionInterface/SendInvoiceEmailActionInterface).
 */
interface AiAssistantServiceInterface
{
    public function complete(Account&ProvidesPlanEntitlements $owner, string $prompt): AiCompletionResult;
}
