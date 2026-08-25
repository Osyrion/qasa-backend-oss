<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Invoicing\Domain\Enums\ReverseChargeMode;
use App\Modules\Shared\Enums\Currency;

/**
 * One issued document, as much of it as a control statement has to classify.
 *
 * The VAT recap is already computed: it comes from the items and the header
 * discount, and re-deriving it outside Invoicing is how an export starts
 * disagreeing with the invoice it represents. What is *not* computed is which
 * section the rows land in — that is a national rule and belongs to Taxation,
 * which is why the reverse-charge mode, the credit-note flag and the gross
 * total travel alongside rather than being folded into a verdict here.
 *
 * The gross stays in the document's own currency with the frozen rate beside
 * it: the CZ threshold is 10 000 Kč, the SK statement has no threshold at all,
 * and converting here would mean this value object picking a residency.
 */
final readonly class VatControlStatementIssuedDocument
{
    /**
     * @param  string  $date  Y-m-d — the taxable supply date, or the issue date when there is none
     * @param  list<VatControlStatementRowData>  $rows  one per VAT rate, without a related document
     */
    public function __construct(
        public string $documentNumber,
        public string $date,
        public string $partnerName,
        public ?string $partnerTaxId,
        /** Null for an ordinary domestic supply — the tax is the supplier's. */
        public ?ReverseChargeMode $reverseChargeMode,
        public bool $isCreditNote,
        /** The document this one corrects — set only for a credit note. */
        public ?string $relatedDocumentNumber,
        public float $grossTotal,
        public Currency $currency,
        /** Rate to the account's national currency, frozen at issue; null when the document already is in it. */
        public ?float $exchangeRate,
        public array $rows,
    ) {}
}
