<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\DTOs\CashDocumentData;
use App\Modules\Invoicing\Application\Services\CashDocumentNumberGenerator;
use App\Modules\Invoicing\Domain\Models\CashDocument;
use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Exceptions\DomainException;
use Carbon\CarbonImmutable;

readonly class CreateCashDocumentAction
{
    public function __construct(
        private CashDocumentNumberGenerator $numbers,
    ) {}

    /**
     * @throws DomainException
     */
    public function execute(Account $owner, CashDocumentData $data): CashDocument
    {
        $ownerId = $owner->accountOwnerId();
        $issuedAt = CarbonImmutable::parse($data->issued_at);

        $this->assertLinkBelongsToAccount($ownerId, $data);

        return $this->numbers->withNumber(
            $ownerId,
            $data->type,
            $issuedAt->year,
            fn (string $number): CashDocument => CashDocument::query()->create([
                'user_id' => $ownerId,
                'type' => $data->type->value,
                'number' => $number,
                'issued_at' => $issuedAt->toDateString(),
                'amount' => $data->amount,
                'currency' => $data->currency->value,
                'vat_rate' => $data->vat_rate,
                'vat_amount' => $data->vat_amount,
                'counterparty' => $data->counterparty,
                'description' => $data->description,
                'note' => $data->note,
                'invoice_payment_id' => $data->invoice_payment_id,
                'expense_id' => $data->expense_id,
            ]),
        );
    }

    /**
     * A link to another account's payment would be invisible under the
     * policy but still stored — and would then silently exclude this
     * document from the owner's tax figures. Rejecting it up front is the
     * difference between a 422 and a wrong tax return.
     *
     * @throws DomainException
     */
    private function assertLinkBelongsToAccount(string $ownerId, CashDocumentData $data): void
    {
        if ($data->invoice_payment_id !== null) {
            $exists = InvoicePayment::query()
                ->where('id', $data->invoice_payment_id)
                ->whereHas('invoice', fn ($query) => $query->where('user_id', $ownerId))
                ->exists();

            if (! $exists) {
                throw DomainException::because(__('invoicing.cash_document_link_not_found'));
            }
        }

        if ($data->expense_id !== null && ! Expense::query()->where('id', $data->expense_id)->exists()) {
            throw DomainException::because(__('invoicing.cash_document_link_not_found'));
        }
    }
}
