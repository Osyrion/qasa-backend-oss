<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Clients\Application\Contracts\ClientDirectory;
use App\Modules\Invoicing\Application\Contracts\QuoteRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\QuoteData;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\ValueObjects\InvoiceNumberMask;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesInvoiceNumbering;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;
use App\Modules\Shared\Domain\Contracts\ProvidesSupplierProfile;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class CreateQuoteAction
{
    public function __construct(
        private ClientDirectory $clients,
        private QuoteRepositoryInterface $repository,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(QuoteData $data, Account&ProvidesInvoiceNumbering&ProvidesPlanEntitlements&ProvidesSupplierProfile $user): Quote
    {
        $this->clients->requireForNewDocument($data->client_id, $user->accountOwnerId());

        $this->validateCurrency($data, $user);

        return DB::transaction(function () use ($data, $user): Quote {
            $numbering = $user->invoiceNumbering();
            $userId = $user->accountOwnerId();

            // Re-checked inside the transaction, as it was before: the
            // pre-flight check above runs outside it.
            if (! $this->clients->existsForAccount($data->client_id, $userId)) {
                throw new ModelNotFoundException;
            }

            $mask = new InvoiceNumberMask(
                $numbering->quoteMask ?? config('invoicing.quote_number_mask', 'CP-{YYYY}-{NNN}')
            );

            $quoteNumber = $this->repository->nextQuoteNumber(
                userId: $userId,
                mask: $mask,
                start: $numbering->quoteStart,
            );

            return $this->repository->create([
                'user_id' => $userId,
                'client_id' => $data->client_id,
                'quote_number' => $quoteNumber,
                'status' => 'draft',
                'issued_at' => $data->issued_at,
                'valid_until' => $data->valid_until,
                'currency' => $data->currency->value,
                'subtotal' => 0,
                'discount_percent' => $data->discount_percent,
                'discount_amount' => 0,
                'vat_amount' => 0,
                'total' => 0,
                'note' => $data->note,
                'note_above' => $data->note_above,
            ]);
        });
    }

    /**
     * @throws DomainException
     */
    private function validateCurrency(QuoteData $data, Account&ProvidesPlanEntitlements&ProvidesSupplierProfile $owner): void
    {
        if ($data->currency !== $owner->supplierProfile()->defaultCurrency && ! $owner->hasFeature('multi_currency')) {
            throw DomainException::because(__('subscriptions.multi_currency_required'));
        }
    }
}
