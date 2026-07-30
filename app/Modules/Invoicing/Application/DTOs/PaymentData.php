<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use Illuminate\Http\Request;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class PaymentData extends Data
{
    public function __construct(
        public readonly float $amount,
        public readonly string $paid_at,

        #[Nullable]
        public readonly ?string $method = null,

        #[Nullable]
        public readonly ?string $note = null,

        #[Nullable]
        public readonly ?string $bank_reference = null,

        /**
         * Set only by StripeWebhookController, constructing this DTO
         * directly — deliberately absent from rules()/fromRequest() so an
         * authenticated client can never inject a fake payment_intent id
         * through POST invoices/{invoice}/payments.
         */
        #[Nullable]
        public readonly ?string $stripe_payment_intent_id = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'paid_at' => ['required', 'date'],
            'method' => ['nullable', 'string', 'in:bank_transfer,cash,card,other'],
            'note' => ['nullable', 'string', 'max:255'],
            'bank_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            amount: (float) $request->input('amount'),
            paid_at: $request->string('paid_at')->toString(),
            method: $request->filled('method') ? $request->string('method')->toString() : null,
            note: $request->filled('note') ? $request->string('note')->toString() : null,
            bank_reference: $request->filled('bank_reference') ? $request->string('bank_reference')->toString() : null,
        );
    }
}
