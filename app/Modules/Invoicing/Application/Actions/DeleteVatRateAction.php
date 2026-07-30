<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\Contracts\VatRateRepositoryInterface;
use App\Modules\Invoicing\Domain\Models\VatRate;

/**
 * InvoiceItem/OrderItem hold no foreign key to the catalog — their numeric
 * rate is a frozen snapshot, so deleting a catalog entry never touches
 * existing documents.
 */
readonly class DeleteVatRateAction
{
    public function __construct(
        private VatRateRepositoryInterface $repository,
    ) {}

    public function execute(VatRate $vatRate): void
    {
        $this->repository->delete($vatRate);
    }
}
