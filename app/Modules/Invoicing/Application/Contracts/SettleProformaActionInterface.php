<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Exceptions\DomainException;
use Throwable;

/**
 * The contract other modules reach through — ModuleBoundariesTest requires
 * cross-module Application-layer dependencies to go via an interface, not a
 * concrete Action class. Used by Automation\AutoSettleProforma (N3 rule 2).
 */
interface SettleProformaActionInterface
{
    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Invoice $proforma, Account $user): Invoice;
}
