<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Application\Contracts\QuoteRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\QuoteData;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\Services\InvoiceNumberMask;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class CreateQuoteAction
{
    public function __construct(
        private QuoteRepositoryInterface $repository,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(QuoteData $data, User $user): Quote
    {
        return DB::transaction(function () use ($data, $user): Quote {
            $owner = $user->accountOwner();
            $userId = $owner->id;

            Client::forUser($userId)->findOrFail($data->client_id);

            $mask = new InvoiceNumberMask(
                $owner->quote_number_mask ?? config('invoicing.quote_number_mask', 'CP-{YYYY}-{NNN}')
            );

            $quoteNumber = $this->repository->nextQuoteNumber(
                userId: $userId,
                mask: $mask,
                start: $owner->quote_number_start ?? 1,
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
}
