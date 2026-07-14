<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\Contracts\BankAccountRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\BankAccountData;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class CreateBankAccountAction
{
    public function __construct(
        private BankAccountRepositoryInterface $repository,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(BankAccountData $data, string $userId): BankAccount
    {
        return DB::transaction(function () use ($data, $userId): BankAccount {
            if ($data->is_default) {
                $this->unsetCurrentDefault($userId, $data->currency->value);
            }

            return $this->repository->create([
                'user_id' => $userId,
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

    private function unsetCurrentDefault(string $userId, string $currency): void
    {
        BankAccount::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('currency', $currency)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
