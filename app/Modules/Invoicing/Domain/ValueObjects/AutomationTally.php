<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Invoicing\Domain\Enums\AutomationKind;

/**
 * How much of one kind of work happened on its own over a period.
 */
final readonly class AutomationTally
{
    public function __construct(
        public AutomationKind $kind,
        public int $total,
        public int $automated,
    ) {}
}
