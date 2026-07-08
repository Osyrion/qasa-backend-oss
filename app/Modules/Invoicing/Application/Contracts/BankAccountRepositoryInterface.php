<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Database\Eloquent\Collection;

interface BankAccountRepositoryInterface
{
    /**
     * @return Collection<int, BankAccount>
     */
    public function allForUser(string $userId): Collection;

    /**
     * The account to preselect for an invoice in the given currency:
     * the currency's default, else any account in that currency.
     */
    public function defaultForCurrency(string $userId, Currency $currency): ?BankAccount;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): BankAccount;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(BankAccount $bankAccount, array $attributes): BankAccount;

    public function delete(BankAccount $bankAccount): void;
}
