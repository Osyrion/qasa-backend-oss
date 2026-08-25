<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\InvoiceImportRegistry;
use App\Modules\Invoicing\Domain\Models\Invoice;

final class EloquentInvoiceImportRegistry implements InvoiceImportRegistry
{
    public function findImported(string $ownerId, string $source, ?string $externalId): ?string
    {
        $id = Invoice::forUser($ownerId)
            ->where('external_source', $source)
            ->where('external_id', $externalId)
            ->value('id');

        return $id === null ? null : (string) $id;
    }

    public function link(string $invoiceId, string $source, ?string $externalId, ?string $issuedNumber): void
    {
        $attributes = ['external_source' => $source, 'external_id' => $externalId];

        if ($issuedNumber !== null) {
            $attributes['invoice_number'] = $issuedNumber;
        }

        Invoice::query()->whereKey($invoiceId)->update($attributes);
    }
}
