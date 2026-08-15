<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Contracts;

/**
 * $suggestions keeps the same shape SupplierInvoiceParser has always
 * produced, so ConvertInboxItemAction and the FE review form need no changes
 * regardless of which driver produced it — an LLM driver's extra fields
 * (e.g. a "line_items" key) are simply additional keys in the same array,
 * not a new migration or resource field.
 */
final readonly class FieldExtractionResult
{
    /**
     * @param  array<string, mixed>  $suggestions
     * @param  'regex'|'ai'|'ai_byok'  $source
     * @param  string|null  $provider  'anthropic'|… — null for the regex source
     * @param  string|null  $model  The exact model that produced $suggestions — null for the
     *                              regex source. Stored with the item so the AI Act art. 50
     *                              marking on an old suggestion keeps naming the model that
     *                              actually read the document, not whatever config says today.
     */
    public function __construct(
        public array $suggestions,
        public string $source,
        public ?string $provider = null,
        public ?string $model = null,
    ) {}
}
