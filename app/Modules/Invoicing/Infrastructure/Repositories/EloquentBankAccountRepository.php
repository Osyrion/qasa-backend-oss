<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Repositories;

use App\Modules\Invoicing\Application\Contracts\BankAccountRepositoryInterface;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Database\Eloquent\Collection;

class EloquentBankAccountRepository implements BankAccountRepositoryInterface
{
    public function allForUser(string $userId): Collection
    {
        return BankAccount::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->orderByDesc('is_default')
            ->orderBy('label')
            ->get();
    }

    public function defaultForCurrency(string $userId, Currency $currency): ?BankAccount
    {
        return BankAccount::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('currency', $currency->value)
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->first();
    }

    public function create(array $attributes): BankAccount
    {
        return BankAccount::create($attributes);
    }

    public function update(BankAccount $bankAccount, array $attributes): BankAccount
    {
        $bankAccount->update($attributes);

        return $bankAccount->refresh();
    }

    public function delete(BankAccount $bankAccount): void
    {
        $bankAccount->delete();
    }
}
