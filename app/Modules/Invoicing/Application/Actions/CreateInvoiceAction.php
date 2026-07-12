<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Contracts\BankAccountRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\InvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\InvoiceData;
use App\Modules\Invoicing\Domain\Events\InvoiceCreated;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Services\InvoiceVatRegimeResolver;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

readonly class CreateInvoiceAction
{
    public function __construct(
        private InvoiceRepositoryInterface $repository,
        private BankAccountRepositoryInterface $bankAccounts,
        private InvoiceVatRegimeResolver $regimeResolver,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(InvoiceData $data, User $user): Invoice
    {
        return DB::transaction(function () use ($data, $user): Invoice {
            $owner = $user->accountOwner();
            $userId = $owner->id;

            $client = Client::forUser($userId)->findOrFail($data->client_id);

            $decision = $this->regimeResolver->resolve(
                $owner->vat_status,
                $owner->country,
                $client,
                $data->reverse_charge,
            );

            $invoiceNumber = $this->repository->nextInvoiceNumber(
                userId: $userId,
                mask: $data->type->numberMask($user),
                start: $owner->invoice_number_start ?? 1,
            );

            $bankAccountId = $data->bank_account_id
                ?? $this->bankAccounts->defaultForCurrency($userId, $data->currency)?->id;

            $invoice = $this->repository->create([
                'user_id' => $userId,
                'client_id' => $data->client_id,
                'invoice_number' => $invoiceNumber,
                'type' => $data->type->value,
                'status' => 'draft',
                'issued_at' => $data->issued_at,
                'taxable_supply_at' => $data->taxable_supply_at
                    ?? ($data->type->isTaxDocument() ? $data->issued_at : null),
                'due_at' => $data->due_at,
                'variable_symbol' => $data->variable_symbol ?? self::variableSymbolFromNumber($invoiceNumber),
                'bank_account_id' => $bankAccountId,
                'currency' => $data->currency->value,
                'subtotal' => 0,
                'discount_percent' => $data->discount_percent,
                'discount_amount' => 0,
                'reverse_charge' => $decision->reverseCharge,
                'reverse_charge_mode' => $decision->mode?->value,
                'vat_amount' => 0,
                'total' => 0,
                'note' => $data->note,
                'note_above' => $data->note_above,
            ]);

            event(new InvoiceCreated($invoice));

            return $invoice;
        });
    }

    /**
     * FA-2026-001 → 2026001 (digits only, max 10 chars)
     */
    public static function variableSymbolFromNumber(string $invoiceNumber): string
    {
        $digits = (string) preg_replace('/\D/', '', $invoiceNumber);

        return Str::substr($digits, -10);
    }
}
