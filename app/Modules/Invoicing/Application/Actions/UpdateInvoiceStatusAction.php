<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Application\Contracts\InvoiceRepositoryInterface;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Events\InvoicePaid;
use App\Modules\Invoicing\Domain\Events\InvoiceSent;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class UpdateInvoiceStatusAction
{
    public function __construct(
        private InvoiceRepositoryInterface $repository,
        private IssueInvoiceAction $issueAction,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Invoice $invoice, InvoiceStatus $newStatus): Invoice
    {
        $currentStatus = InvoiceStatus::from($invoice->status);

        $this->assertTransition($currentStatus, $newStatus);

        // The ČNB fetch is external I/O — resolve it before the transaction
        // below holds a row lock and an open connection.
        $exchangeRate = $currentStatus === InvoiceStatus::Draft && $newStatus === InvoiceStatus::Sent
            ? $this->issueAction->resolveExchangeRateSnapshot($invoice)
            : null;

        return DB::transaction(function () use ($invoice, $newStatus, $exchangeRate): Invoice {
            // Re-read under a row lock and re-validate: a concurrent request
            // (e.g. two parallel send calls) may have advanced the status
            // since the check above.
            $invoice = Invoice::query()->lockForUpdate()->whereKey($invoice->getKey())->firstOrFail();
            $currentStatus = InvoiceStatus::from($invoice->status);

            $this->assertTransition($currentStatus, $newStatus);

            // Issuance side effects (snapshots, ČNB rate, VS/DUZP defaults)
            if ($currentStatus === InvoiceStatus::Draft && $newStatus === InvoiceStatus::Sent) {
                $invoice = $this->issueAction->execute($invoice, $exchangeRate);
            }

            $updated = $this->repository->update($invoice, [
                'status' => $newStatus->value,
            ]);

            match ($newStatus) {
                InvoiceStatus::Sent => event(new InvoiceSent($updated)),
                InvoiceStatus::Paid => event(new InvoicePaid($updated)),
                default => null,
            };

            return $updated;
        });
    }

    /**
     * @throws DomainException
     */
    private function assertTransition(InvoiceStatus $from, InvoiceStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw DomainException::because(
                __('invoicing.status_transition_not_allowed', ['from' => $from->label(), 'to' => $to->label()])
            );
        }
    }
}
