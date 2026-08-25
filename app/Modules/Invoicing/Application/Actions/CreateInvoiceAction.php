<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Clients\Application\Contracts\ClientDirectory;
use App\Modules\Invoicing\Application\Contracts\AddInvoiceItemActionInterface;
use App\Modules\Invoicing\Application\Contracts\BankAccountRepositoryInterface;
use App\Modules\Invoicing\Application\Contracts\CreateInvoiceActionInterface;
use App\Modules\Invoicing\Application\Contracts\InvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\InvoiceData;
use App\Modules\Invoicing\Application\DTOs\InvoiceItemData;
use App\Modules\Invoicing\Domain\Events\InvoiceCreated;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\ValueObjects\InvoiceItemDraft;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;
use App\Modules\Shared\Domain\Contracts\ProvidesSupplierProfile;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Taxation\Application\Contracts\TaxSystemResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

readonly class CreateInvoiceAction implements CreateInvoiceActionInterface
{
    public function __construct(
        private ClientDirectory $clients,
        private InvoiceRepositoryInterface $repository,
        private BankAccountRepositoryInterface $bankAccounts,
        private TaxSystemResolverInterface $taxSystemResolver,
        private AddInvoiceItemActionInterface $addItem,
    ) {}

    /**
     * @param  list<InvoiceItemDraft>  $items
     *
     * @throws DomainException
     * @throws Throwable
     */
    public function create(
        InvoiceData $data,
        Account&ProvidesPlanEntitlements&ProvidesSupplierProfile $user,
        array $items = [],
    ): string {
        // One transaction over both halves: a document that came into being
        // with lines must not be able to exist without them. execute()'s own
        // transaction nests into this as a savepoint.
        return DB::transaction(function () use ($data, $user, $items): string {
            $invoice = $this->execute($data, $user);

            foreach ($items as $draft) {
                $this->addItem->execute($invoice, new InvoiceItemData(
                    description: $draft->description,
                    quantity: $draft->quantity,
                    unit: $draft->unit,
                    unit_price: $draft->unitPrice,
                    vat_rate: $draft->vatRate,
                    sort_order: $draft->sortOrder,
                ));
            }

            return $invoice->id;
        });
    }

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(InvoiceData $data, Account&ProvidesPlanEntitlements&ProvidesSupplierProfile $user): Invoice
    {

        $client = $this->clients->requireForNewDocument($data->client_id, $user->accountOwnerId());

        $this->validateCurrency($data, $user);

        return DB::transaction(function () use ($data, $user, $client): Invoice {
            $userId = $user->accountOwnerId();
            $profile = $user->supplierProfile();

            $decision = $this->taxSystemResolver->forSupplier($profile)->vatRegimeResolver()->resolve(
                $profile->vatStatus,
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
    private function validateCurrency(InvoiceData $data, Account&ProvidesPlanEntitlements&ProvidesSupplierProfile $owner): void
    {
        if ($data->currency !== $owner->supplierProfile()->defaultCurrency && ! $owner->hasFeature('multi_currency')) {
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
