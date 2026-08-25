<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\ValueObjects;

use App\Modules\Shared\Enums\Currency;

/**
 * The account a payment batch is paid *from*.
 *
 * The counterpart of {@see VendorPaymentAccount}, which is where it goes. Its
 * currency is what makes a supplier invoice selectable at all — a batch is one
 * currency, because the file formats are.
 */
final readonly class PayerAccount
{
    public function __construct(
        public string $id,
        public Currency $currency,
        public DocumentBankAccount $details,
    ) {}

    /**
     * What a batch freezes as its payer — the same six keys a document freezes.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return $this->details->toSnapshot();
    }
}
