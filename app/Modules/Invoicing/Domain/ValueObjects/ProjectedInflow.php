<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Carbon;

/**
 * Money a recurring template is expected to bring in on a date no document
 * exists for yet.
 *
 * A forecast is the one place where "what will be invoiced" is worth as much
 * as "what has been", and working it out means knowing how a template
 * recurs — its period, its day of month, its end date, its payment terms and
 * the VAT on its items. All of that is Invoicing's, so the projection happens
 * there and dated amounts come out; how they are bucketed into weeks is the
 * forecast's own business.
 */
final readonly class ProjectedInflow
{
    public function __construct(
        public Currency $currency,
        /** When the projected document would fall due, not when it would be issued. */
        public Carbon $dueAt,
        public float $amount,
    ) {}
}
