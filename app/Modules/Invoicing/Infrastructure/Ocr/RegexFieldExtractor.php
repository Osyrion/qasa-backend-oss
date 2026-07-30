<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ocr;

use App\Modules\Invoicing\Domain\Contracts\FieldExtractionInput;
use App\Modules\Invoicing\Domain\Contracts\FieldExtractionResult;
use App\Modules\Invoicing\Domain\Contracts\InvoiceFieldExtractor;
use App\Modules\Invoicing\Domain\Services\SupplierInvoiceParser;

/**
 * Adapts the pure heuristic SupplierInvoiceParser to InvoiceFieldExtractor —
 * the parser itself stays free of VO/contract dependencies.
 */
final readonly class RegexFieldExtractor implements InvoiceFieldExtractor
{
    public function __construct(
        private SupplierInvoiceParser $parser,
    ) {}

    public function parse(FieldExtractionInput $input): FieldExtractionResult
    {
        return new FieldExtractionResult(
            suggestions: $this->parser->parse($input->text),
            source: 'regex',
        );
    }
}
