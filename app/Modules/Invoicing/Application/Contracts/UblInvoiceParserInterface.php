<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

/**
 * Reads an inbound UBL 2.1 invoice into the field suggestions the inbox
 * settles from, so an e-invoice never has to go through OCR.
 */
interface UblInvoiceParserInterface
{
    /**
     * Cheap shape check before committing to a full parse.
     */
    public function looksLikeUbl(string $xml): bool;

    /**
     * @return array<string, mixed>|null null when the payload is not UBL
     */
    public function parse(string $xml): ?array;
}
