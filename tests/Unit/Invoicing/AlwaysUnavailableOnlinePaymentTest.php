<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\ValueObjects\PublicInvoice;
use App\Modules\Invoicing\Infrastructure\Payments\AlwaysUnavailableOnlinePayment;
use App\Modules\Shared\Enums\Currency;

it('is never available — the OSS core default with no Stripe Connect', function (): void {
    // Payable on every count the value carries, so a false answer can only be
    // the default itself and not one of the guards.
    $invoice = new PublicInvoice(
        id: 'e1f9d0a2-0000-4000-8000-000000000001',
        ownerId: 'e1f9d0a2-0000-4000-8000-000000000002',
        number: 'FA-1',
        paymentDescription: 'Faktúra FA-1',
        status: 'sent',
        currency: Currency::EUR,
        total: 100.0,
        balance: 100.0,
        publicUrl: 'https://example.test/i/token',
        isCancelled: false,
        isCreditNote: false,
    );

    expect($invoice->isPayable())->toBeTrue()
        ->and((new AlwaysUnavailableOnlinePayment)->isAvailableFor($invoice))->toBeFalse();
});
