<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Application\Contracts\ClientUsageGuardInterface;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Contracts\BankAccountRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\InvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\InvoiceData;
use App\Modules\Invoicing\Domain\Events\InvoiceCreated;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Taxation\Application\Contracts\TaxSystemResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

readonly class CreateInvoiceAction
{
    public function __construct(
        private InvoiceRepositoryInterface $repository,
        private BankAccountRepositoryInterface $bankAccounts,
        private TaxSystemResolverInterface $taxSystemResolver,
        private ClientUsageGuardInterface $usageGuard,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(InvoiceData $data, User $user): Invoice
    {
        $client = Client::query()->find($data->client_id);

        if ($client !== null) {
            if ($client->isArchived()) {
                throw DomainException::because(__('clients.archived'));
            }

            $this->usageGuard->ensureUsable($client);
        }

        $this->validateCurrency($data, $user->accountOwner());

        return DB::transaction(function () use ($data, $user): Invoice {
            $owner = $user->accountOwner();
            $userId = $owner->id;

            $client = Client::forUser($userId)->findOrFail($data->client_id);

            $decision = $this->taxSystemResolver->forUser($owner)->vatRegimeResolver()->resolve(
                $owner->vat_status,
                $client,
                $data->reverse_charge,
            );

            $bankAccountId = $data->bank_account_id
                ?? $this->bankAccounts->resolveForCurrency($userId, $data->currency)?->id;

            // The invoice number (and the VS derived from it) is assigned by
            // IssueInvoiceAction at the draft → issued transition, so deleted
            // drafts never leave gaps in the sequence.
            $invoice = $this->repository->create([
                'user_id' => $userId,
                'client_id' => $data->client_id,
                'invoice_number' => null,
                'type' => $data->type->value,
                'status' => 'draft',
                'issued_at' => $data->issued_at,
                'taxable_supply_at' => $data->taxable_supply_at
                    ?? ($data->type->isTaxDocument() ? $data->issued_at : null),
                'due_at' => $data->due_at,
                'variable_symbol' => $data->variable_symbol,
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
     * @throws DomainException
     */
    private function validateCurrency(InvoiceData $data, User $owner): void
    {
        if ($data->currency !== $owner->default_currency && ! $owner->hasFeature('multi_currency')) {
            throw DomainException::because(__('subscriptions.multi_currency_required'));
        }
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
