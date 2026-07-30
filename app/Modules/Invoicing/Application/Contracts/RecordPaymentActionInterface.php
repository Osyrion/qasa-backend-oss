<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Application\DTOs\PaymentData;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use App\Modules\Shared\Exceptions\DomainException;
use Throwable;

interface RecordPaymentActionInterface
{
    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Invoice $invoice, PaymentData $data, bool $enforceUsageGuard = true): InvoicePayment;
}
