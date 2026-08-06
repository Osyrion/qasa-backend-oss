<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\DTOs\AiCompletionResult;

/**
 * Cross-module boundary for AiAssistantService — premium modules (Reports,
 * Automation) depend on this contract, never the concrete service, per
 * tests/Architecture/ModuleBoundariesTest.php (same pattern as
 * SettleProformaActionInterface/SendInvoiceEmailActionInterface).
 */
interface AiAssistantServiceInterface
{
    public function complete(User $owner, string $prompt): AiCompletionResult;
}
