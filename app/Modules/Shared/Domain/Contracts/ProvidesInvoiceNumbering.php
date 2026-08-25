<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

use App\Modules\Shared\Domain\ValueObjects\InvoiceNumberingProfile;

/**
 * An account that can describe how it numbers its documents.
 *
 * Implemented by the edition's User model, which is also what resolves the
 * account owner — a team member's documents carry the owner's series, and
 * making every numbering site remember that was how `accountOwner()` ended up
 * called four times in one expression.
 */
interface ProvidesInvoiceNumbering
{
    public function invoiceNumbering(): InvoiceNumberingProfile;
}
