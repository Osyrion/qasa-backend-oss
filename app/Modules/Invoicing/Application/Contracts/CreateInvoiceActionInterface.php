<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Application\DTOs\InvoiceData;
use App\Modules\Invoicing\Domain\ValueObjects\InvoiceItemDraft;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;
use App\Modules\Shared\Domain\Contracts\ProvidesSupplierProfile;
use App\Modules\Shared\Exceptions\DomainException;
use Throwable;

/**
 * Drafting an invoice from outside Invoicing.
 *
 * Returns the new document's id and takes its opening lines as drafts, rather
 * than handing back the aggregate and letting the caller add items to it: a
 * module that holds the model has published the `invoices` table, and one that
 * builds its own line rows is a second place VAT rounding is decided.
 * Invoicing's own actions and controller keep the concrete
 * CreateInvoiceAction/AddInvoiceItemAction, which they legitimately hold.
 */
interface CreateInvoiceActionInterface
{
    /**
     * @param  list<InvoiceItemDraft>  $items  lines to create with the document
     * @return string the new invoice's id
     *
     * @throws DomainException
     * @throws Throwable
     */
    public function create(
        InvoiceData $data,
        Account&ProvidesPlanEntitlements&ProvidesSupplierProfile $user,
        array $items = [],
    ): string;
}
