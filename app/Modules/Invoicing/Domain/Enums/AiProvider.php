<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Enums;

/**
 * Supported BYOK LLM providers for invoice field extraction. Adding a
 * provider is: a new case here, a new LlmProviderDriver implementation, and
 * one tagged-binding line in InvoicingServiceProvider — see
 * docs/plans/BYOK_MULTI_PROVIDER_EXTRACTION_PLAN.md.
 */
enum AiProvider: string
{
    case Anthropic = 'anthropic';
}
