<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Domain\Models\VatRate;

/**
 * Invoice/template items hold no foreign key to the catalog (see
 * App\Modules\Invoicing\Domain\Rules\VatRateInCatalog) — their numeric rate
 * is a frozen snapshot, so deleting a catalog entry never touches existing
 * documents.
 */
readonly class DeleteVatRateAction
{
    public function execute(VatRate $vatRate): void
    {
        $vatRate->delete();
    }
}
