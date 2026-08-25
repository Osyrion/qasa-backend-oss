<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Shared\Enums\Currency;
use Illuminate\Support\Carbon;

/**
 * A received document as something that prints it reads it — the counterpart
 * to {@see RenderableInvoice}, and much smaller, because a received invoice is
 * recorded rather than composed: it has no items, only the VAT split its
 * sender declared.
 */
final readonly class RenderableSupplierInvoice
{
    /**
     * @param  list<VatRecapRow>  $vatLines  as recorded on the document, one per VAT rate
     */
    public function __construct(
        public string $id,
        public string $number,
        public Currency $currency,
        public Carbon $issuedAt,
        public ?Carbon $dueAt,
        public ?Carbon $taxableSupplyAt,
        public ?string $variableSymbol,
        public float $total,
        public ?DocumentParty $vendor,
        public array $vatLines,
    ) {}
}
