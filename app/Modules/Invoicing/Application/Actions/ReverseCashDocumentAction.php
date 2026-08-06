<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\Services\CashDocumentNumberGenerator;
use App\Modules\Invoicing\Domain\Events\CashDocumentReversed;
use App\Modules\Invoicing\Domain\Models\CashDocument;
use App\Modules\Shared\Exceptions\DomainException;

/**
 * Cancels a cash document by issuing its mirror image.
 *
 * This is the only correction path there is — cash documents are never
 * updated or deleted. Both rows stay in the book and net to zero, which is
 * what a paper cash book does and what makes the history auditable.
 */
readonly class ReverseCashDocumentAction
{
    public function __construct(
        private CashDocumentNumberGenerator $numbers,
    ) {}

    /**
     * @throws DomainException
     */
    public function execute(CashDocument $document): CashDocument
    {
        if ($document->isReversal()) {
            throw DomainException::because(__('invoicing.cash_document_reversal_not_reversible'));
        }

        if ($document->reversal()->exists()) {
            throw DomainException::because(__('invoicing.cash_document_already_reversed'));
        }

        $type = $document->type->opposite();

        $reversal = $this->numbers->withNumber(
            $document->user_id,
            $type,
            (int) $document->issued_at->year,
            fn (string $number): CashDocument => CashDocument::query()->create([
                'user_id' => $document->user_id,
                'type' => $type->value,
                'number' => $number,
                // Dated to the original, not to today: the correction belongs
                // to the period the mistake was made in, or the cash book
                // balance would be wrong on both dates.
                'issued_at' => $document->issued_at->toDateString(),
                'amount' => $document->amount,
                'currency' => $document->currency->value,
                'vat_rate' => $document->vat_rate,
                'vat_amount' => $document->vat_amount,
                'counterparty' => $document->counterparty,
                'description' => __('invoicing.cash_document_reversal_of', ['number' => $document->number]),
                'reverses_cash_document_id' => $document->id,
            ]),
        );

        event(new CashDocumentReversed($document, $reversal));

        return $reversal;
    }
}
