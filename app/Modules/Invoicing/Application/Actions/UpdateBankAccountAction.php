<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\Contracts\BankAccountRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\BankAccountData;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class UpdateBankAccountAction
{
    public function __construct(
        private BankAccountRepositoryInterface $repository,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(BankAccount $bankAccount, BankAccountData $data): BankAccount
    {
        return DB::transaction(function () use ($bankAccount, $data): BankAccount {
            if ($data->is_default) {
                BankAccount::withoutGlobalScope('user')
                    ->where('user_id', $bankAccount->user_id)
                    ->where('currency', $data->currency->value)
                    ->where('is_default', true)
                    ->whereKeyNot($bankAccount->id)
                    ->update(['is_default' => false]);
            }

            return $this->repository->update($bankAccount, [
                'label' => $data->label,
                'bank_name' => $data->bank_name,
                'account_number' => $data->account_number,
                'iban' => $data->iban,
                'bic' => $data->bic,
                'currency' => $data->currency->value,
                'is_default' => $data->is_default,
            ]);
        });
    }
}
