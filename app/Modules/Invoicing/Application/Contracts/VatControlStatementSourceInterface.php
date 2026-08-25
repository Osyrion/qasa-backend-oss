<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\ValueObjects\VatControlStatementIssuedDocument;
use App\Modules\Invoicing\Domain\ValueObjects\VatControlStatementReceivedDocument;

/**
 * Source of the documents a VAT control statement is built from.
 *
 * Taxation owns the SK/CZ statement layouts, Invoicing owns the invoices; this
 * is the seam between them. It used to hand over `Collection<int, Invoice>`,
 * which made it a seam in name only — what actually crossed was the aggregate
 * and its relations, so both residency services walked `$invoice->vatLines`,
 * read `client_snapshot` by string key and called `VatRecapCalculator`
 * themselves. Three ways for a classifier to disagree with the document it is
 * classifying.
 *
 * Now values cross, already reduced to one row per VAT rate. Rows rather than
 * sums because the set is bounded — one account's documents for a quarter —
 * and because the recap cannot be reproduced in SQL without moving the
 * numbers: it is derived from the items and the header discount, rounded per
 * rate bucket.
 *
 * What is deliberately *not* decided here is which section a document belongs
 * in, or whether it clears a threshold. Those are national rules, they differ
 * between the two implementations that read this, and Invoicing has no
 * business holding an opinion about either.
 */
interface VatControlStatementSourceInterface
{
    /**
     * @param  list<string>  $months  "Y-m" months in scope
     * @return list<VatControlStatementIssuedDocument>
     */
    public function collectIssuedDocuments(string $userId, array $months): array;

    /**
     * @param  list<string>  $months  "Y-m" months in scope
     * @return list<VatControlStatementReceivedDocument>
     */
    public function collectReceivedDocuments(string $userId, array $months): array;

    /**
     * @return list<string> "Y-m" months in scope
     */
    public function monthsInScope(int $year, ?int $quarter, ?int $month): array;
}
