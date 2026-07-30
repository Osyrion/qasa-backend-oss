<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\DTOs;

use Carbon\CarbonInterface;

/**
 * One unit of tracked, not-yet-invoiced work on an order, reduced to what
 * invoicing needs to turn it into an invoice line.
 *
 * Invoicing bills work without knowing that TimeTracking (and its TimeEntry
 * model) exists — the OSS edition simply never has any.
 */
final readonly class BillableWork
{
    public function __construct(
        public string $id,
        public float $hours,
        public ?float $rateOverride,
        public CarbonInterface $workedAt,
        public float $vatRate,
        public ?string $description,
    ) {}
}
