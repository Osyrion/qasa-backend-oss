<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Domain\Enums\QuoteStatus;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\ValueObjects\DocumentParty;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Handles every quote status transition, manual or automatic (email send,
 * public accept/reject). The draft -> sent transition is the only moment a
 * quote freezes its supplier/client snapshot — a quote never has a separate
 * "issue" step like an invoice does.
 */
readonly class UpdateQuoteStatusAction
{
    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Quote $quote, QuoteStatus $newStatus): Quote
    {
        $currentStatus = $quote->statusEnum();

        $this->assertTransition($currentStatus, $newStatus);

        return DB::transaction(function () use ($quote, $newStatus): Quote {
            // Re-read under a row lock and re-validate: a concurrent request
            // may have advanced the status since the check above.
            $quote = Quote::query()->lockForUpdate()->whereKey($quote->getKey())->firstOrFail();
            $currentStatus = $quote->statusEnum();

            $this->assertTransition($currentStatus, $newStatus);

            $updateData = ['status' => $newStatus->value];

            if ($currentStatus === QuoteStatus::Draft && $newStatus === QuoteStatus::Sent) {
                $quote->loadMissing(['user', 'client']);
                $updateData = [...$updateData, ...$this->snapshotData($quote)];
            }

            if ($newStatus === QuoteStatus::Accepted) {
                $updateData['accepted_at'] = now();
            }

            if ($newStatus === QuoteStatus::Rejected) {
                $updateData['rejected_at'] = now();
            }

            $quote->fill($updateData)->save();

            return $quote->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotData(Quote $quote): array
    {
        $user = $quote->user;
        $client = $quote->client;

        assert($user !== null);

        return [
            'supplier_snapshot' => $user->supplierProfile()->toSnapshot(),
            'client_snapshot' => $client === null
                ? null
                : DocumentParty::fromProfile($client->profile(), $client->peppol_id)->toSnapshot(),
        ];
    }

    /**
     * @throws DomainException
     */
    private function assertTransition(QuoteStatus $from, QuoteStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw DomainException::because(
                __('invoicing.status_transition_not_allowed', ['from' => $from->label(), 'to' => $to->label()])
            );
        }
    }
}
