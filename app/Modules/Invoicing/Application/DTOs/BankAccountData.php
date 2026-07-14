<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use App\Modules\Shared\Enums\Currency;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class BankAccountData extends Data
{
    public function __construct(
        #[Max(100)]
        public readonly string $label,

        public readonly Currency $currency,

        #[Nullable, Max(100)]
        public readonly ?string $bank_name = null,

        #[Nullable, Max(30)]
        public readonly ?string $account_number = null,

        #[Nullable, Max(34)]
        public readonly ?string $iban = null,

        #[Nullable, Max(11)]
        public readonly ?string $bic = null,

        public readonly bool $is_default = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:100'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'account_number' => ['nullable', 'string', 'max:30'],
            'iban' => ['nullable', 'string', 'max:34', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{1,30}$/'],
            'bic' => ['nullable', 'string', 'max:11', 'regex:/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            label: $request->string('label')->toString(),
            currency: Currency::from($request->string('currency')->toString()),
            bank_name: $request->filled('bank_name') ? $request->string('bank_name')->toString() : null,
            account_number: $request->filled('account_number') ? $request->string('account_number')->toString() : null,
            iban: $request->filled('iban') ? $request->string('iban')->toString() : null,
            bic: $request->filled('bic') ? $request->string('bic')->toString() : null,
            is_default: $request->boolean('is_default'),
        );
    }
}
