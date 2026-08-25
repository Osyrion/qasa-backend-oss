<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Shared\Enums\Currency;

/**
 * One received (supplier) document, in the shape a control statement reads.
 *
 * The counterpart to VatControlStatementIssuedDocument — see that class for
 * why the classification inputs travel unresolved. The difference is where the
 * rows come from: a supplier invoice carries its VAT split as recorded rows
 * rather than derived from items, and whether the tax is self-assessed is a
 * property of the document's regime rather than of a reverse-charge mode.
 */
final readonly class VatControlStatementReceivedDocument
{
    /**
     * @param  string  $date  Y-m-d — the taxable supply date, or the issue date when there is none
     * @param  list<VatControlStatementRowData>  $rows  as recorded on the document, one per VAT rate
     */
    public function __construct(
        public string $documentNumber,
        public string $date,
        public string $partnerName,
        public ?string $partnerTaxId,
        /** Tax accounted for by the buyer — EU acquisitions and domestic reverse charge alike. */
        public bool $selfAssessed,
        public float $grossTotal,
        public Currency $currency,
        /** Rate to the account's national currency as recorded; null when the document already is in it. */
        public ?float $exchangeRate,
        public array $rows,
    ) {}
}
