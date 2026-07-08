<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application\DTOs;

use App\Modules\Pricing\Domain\Enums\RateLevel;
use App\Modules\Shared\Enums\Currency;
use Carbon\CarbonImmutable;

/**
 * Result of resolving the effective billing rate for a given work date.
 */
final readonly class RateResolution
{
    public function __construct(
        public float $rate,
        public ?Currency $currency,
        public RateLevel $level,
        public CarbonImmutable $validFrom,
    ) {}
}
