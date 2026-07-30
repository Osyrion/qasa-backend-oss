<?php

declare(strict_types=1);

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Infrastructure\Payments\AlwaysUnavailableOnlinePayment;

it('is never available — the OSS core default with no Stripe Connect', function (): void {
    // client_id is given so the factory does not reach for a client: the
    // default builds one, which needs a bound account, and this check never
    // touches the database at all.
    $invoice = Invoice::factory()->make(['client_id' => null]);

    expect((new AlwaysUnavailableOnlinePayment)->isAvailableFor($invoice))->toBeFalse();
});
