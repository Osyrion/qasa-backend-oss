<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\DTOs\InvoiceData;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Exceptions\DomainException;
use Throwable;

interface CreateInvoiceActionInterface
{
    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(InvoiceData $data, User $user): Invoice;
}
