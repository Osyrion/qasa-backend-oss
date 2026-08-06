<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * A VAT filing period (month or quarter, never both) whose statutory
 * deadline is now within reminder range — see VatFilingDeadline.
 */
final readonly class VatFilingPeriod
{
    public function __construct(
        public int $year,
        public ?int $quarter,
        public ?int $month,
        public CarbonImmutable $deadline,
    ) {}
}
