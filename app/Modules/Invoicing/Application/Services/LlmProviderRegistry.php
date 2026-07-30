<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Domain\Contracts\LlmProviderDriver;
use App\Modules\Invoicing\Domain\Enums\AiProvider;
use RuntimeException;

/**
 * Looks up the LlmProviderDriver for a given AiProvider — built from the
 * 'llm.providers' tagged binding in InvoicingServiceProvider. Adding a
 * provider is a new driver class plus one tag line there; nothing here
 * changes.
 */
final class LlmProviderRegistry
{
    /**
     * @param  iterable<LlmProviderDriver>  $drivers
     */
    public function __construct(
        private readonly iterable $drivers,
    ) {}

    public function driverFor(AiProvider $provider): LlmProviderDriver
    {
        foreach ($this->drivers as $driver) {
            if ($driver->provider() === $provider) {
                return $driver;
            }
        }

        throw new RuntimeException("No LLM driver registered for provider [{$provider->value}].");
    }
}
