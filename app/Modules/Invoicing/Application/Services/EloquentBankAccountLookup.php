<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Application\Contracts\BankAccountLookup;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\ValueObjects\DocumentBankAccount;
use App\Modules\Invoicing\Domain\ValueObjects\PayerAccount;

final class EloquentBankAccountLookup implements BankAccountLookup
{
    public function requirePayerAccount(string $bankAccountId): PayerAccount
    {
        /** @var BankAccount $account */
        $account = BankAccount::query()->findOrFail($bankAccountId);

        $details = DocumentBankAccount::fromSnapshot($account->toSnapshot());

        assert($details !== null);

        return new PayerAccount(
            id: $account->id,
            currency: $account->currency,
            details: $details,
        );
    }
}
