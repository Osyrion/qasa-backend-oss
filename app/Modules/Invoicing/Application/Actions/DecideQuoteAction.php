<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Domain\Enums\QuoteStatus;
use App\Modules\Invoicing\Domain\Events\QuoteAccepted;
use App\Modules\Invoicing\Domain\Events\QuoteRejected;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The client's public accept/reject decision — one-shot and definitive.
 * A tenant recording the same decision manually (e.g. client agreed over
 * the phone) goes through UpdateQuoteStatusAction instead.
 */
readonly class DecideQuoteAction
{
    public function __construct(
        private UpdateQuoteStatusAction $updateStatusAction,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function accept(Quote $quote, ?string $decisionNote, ?string $decisionIp): Quote
    {
        return $this->decide($quote, QuoteStatus::Accepted, $decisionNote, $decisionIp);
    }

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function reject(Quote $quote, ?string $decisionNote, ?string $decisionIp): Quote
    {
        return $this->decide($quote, QuoteStatus::Rejected, $decisionNote, $decisionIp);
    }

    /**
     * @throws DomainException
     * @throws Throwable
     */
    private function decide(Quote $quote, QuoteStatus $decision, ?string $decisionNote, ?string $decisionIp): Quote
    {
        if ($quote->effectiveStatus() === QuoteStatus::Expired) {
            throw DomainException::because(__('invoicing.quote_expired'));
        }

        if ($quote->statusEnum() !== QuoteStatus::Sent) {
            throw DomainException::because(__('invoicing.quote_already_decided'));
        }

        return DB::transaction(function () use ($quote, $decision, $decisionNote, $decisionIp): Quote {
            $updated = $this->updateStatusAction->execute($quote, $decision);

            $updated->forceFill([
                'decision_note' => $decisionNote,
                'decision_ip' => $decisionIp,
            ])->save();

            match ($decision) {
                QuoteStatus::Accepted => event(new QuoteAccepted($updated)),
                QuoteStatus::Rejected => event(new QuoteRejected($updated)),
                default => null,
            };

            return $updated;
        });
    }
}
