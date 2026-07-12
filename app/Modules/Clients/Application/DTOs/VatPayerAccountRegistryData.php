<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\DTOs;

use Spatie\LaravelData\Data;

/**
 * Result of a VAT payer account register lookup (CZ CRPDPH; an SK
 * implementation can slot in behind the same interface).
 */
class VatPayerAccountRegistryData extends Data
{
    /**
     * @param  list<VatPayerAccountData>  $accounts
     */
    public function __construct(
        public readonly bool $found,
        public readonly bool $unreliable,
        public readonly array $accounts,
    ) {}
}
