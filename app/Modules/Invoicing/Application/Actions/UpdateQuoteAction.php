<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\DTOs\QuoteData;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class UpdateQuoteAction
{
    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Quote $quote, QuoteData $data): Quote
    {
        if (! $quote->isEditable()) {
            throw DomainException::because(__('invoicing.quote_not_editable'));
        }

        return DB::transaction(function () use ($quote, $data): Quote {
            $ownerId = $quote->user_id;
            Client::forUser($ownerId)->findOrFail($data->client_id);

            $quote->fill([
                'client_id' => $data->client_id,
                'issued_at' => $data->issued_at,
                'valid_until' => $data->valid_until,
                'currency' => $data->currency->value,
                'discount_percent' => $data->discount_percent,
                'note' => $data->note,
                'note_above' => $data->note_above,
            ]);

            $quote->load('items');
            $quote->recalculateTotals()->save();

            return $quote;
        });
    }
}
