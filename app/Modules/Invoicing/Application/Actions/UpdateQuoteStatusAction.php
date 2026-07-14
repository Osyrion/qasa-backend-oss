<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Invoicing\Domain\Enums\QuoteStatus;
use App\Modules\Invoicing\Domain\Models\Quote;
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
            'supplier_snapshot' => [
                'name' => $user->full_name,
                'ico' => $user->ico,
                'dic' => $user->dic,
                'vat_id' => $user->vat_id,
                'is_vat_payer' => $user->is_vat_payer,
                'vat_status' => $user->vat_status->value,
                'address' => $user->address,
                'city' => $user->city,
                'postal_code' => $user->postal_code,
                'country' => $user->country,
                'email' => $user->email,
                'phone' => $user->phone,
                'website' => $user->website,
                'logo_path' => $user->logo_path,
                'invoice_footer_text' => $user->invoice_footer_text,
            ],
            'client_snapshot' => $client === null ? null : [
                'name' => $client->display_name,
                'ico' => $client->ico,
                'dic' => $client->dic,
                'vat_id' => $client->vat_id,
                'is_vat_payer' => $client->is_vat_payer,
                'address' => $client->address,
                'city' => $client->city,
                'postal_code' => $client->postal_code,
                'country' => $client->country,
                'email' => $client->email,
                'phone' => $client->phone,
            ],
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
