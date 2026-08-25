<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\ValueObjects\PayerAccount;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The account a payment goes out from, for a module that does not own it.
 *
 * `BankAccountRepositoryInterface` stays what Invoicing's own screens use — it
 * returns the model, which they legitimately hold. This is the outside view:
 * an id in, a value out, and the account scope turning a foreign id into the
 * same 404 the hand-written `findOrFail()` produced.
 */
interface BankAccountLookup
{
    /**
     * @throws ModelNotFoundException when the current account does not own it
     */
    public function requirePayerAccount(string $bankAccountId): PayerAccount;
}
