<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Application\DTOs\PaymentData;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use App\Modules\Shared\Enums\Provenance;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

interface RecordPaymentActionInterface
{
    /**
     * Record an incoming payment against the document with this id.
     *
     * **An id, not the model.** Invoicing naming its own aggregate in its own
     * contract would be fine; what is not fine is that it forced every caller
     * outside Invoicing to name it too, just to have something to pass. Both
     * of them — Banking's statement matcher and the Stripe webhook — were
     * loading a model they had no other use for. The action re-reads under a
     * row lock regardless (three callers reach it concurrently), so the model
     * they handed over was never the one it decided on.
     *
     * @throws ModelNotFoundException when the account cannot see $invoiceId
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(string $invoiceId, PaymentData $data, bool $enforceUsageGuard = true, Provenance $provenance = Provenance::Manual): InvoicePayment;
}
