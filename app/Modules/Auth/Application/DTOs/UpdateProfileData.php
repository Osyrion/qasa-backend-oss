<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\DTOs;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Rules\ValidInvoiceNumberMask;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Enums\VatStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Sometimes;
use Spatie\LaravelData\Data;

class UpdateProfileData extends Data
{
    public function __construct(
        #[Sometimes, Max(100)]
        public readonly ?string $title = null,

        #[Sometimes, Max(100)]
        public readonly ?string $name = null,

        #[Sometimes, Max(100)]
        public readonly ?string $surname = null,

        #[Sometimes, Email, Max(255)]
        public readonly ?string $email = null,

        #[Sometimes, Max(30)]
        public readonly ?string $phone = null,

        #[Sometimes, Min(8), Max(255)]
        public readonly ?string $password = null,

        #[Sometimes, Max(20)]
        public readonly ?string $ico = null,

        #[Sometimes, Max(20)]
        public readonly ?string $dic = null,

        /**
         * Deprecated. Kept for backwards compatibility with older clients;
         * vat_status wins whenever both are sent.
         */
        #[Sometimes]
        public readonly ?bool $is_vat_payer = null,

        #[Sometimes]
        public readonly ?VatStatus $vat_status = null,

        #[Sometimes]
        public readonly ?int $tax_flat_rate = null,

        #[Sometimes]
        public readonly Currency $default_currency = Currency::EUR,

        #[Sometimes, Max(10)]
        public readonly ?string $invoice_prefix = null,

        #[Sometimes, Max(40)]
        public readonly ?string $invoice_number_mask = null,

        /**
         * Whether "invoice_number_mask" was present in the request at all —
         * distinguishes "not sent" (leave unchanged) from "" (reset to the
         * legacy default), which a plain nullable value cannot express.
         */
        public readonly bool $invoice_number_mask_provided = false,

        #[Sometimes]
        public readonly ?int $invoice_number_start = null,

        public readonly bool $invoice_number_start_provided = false,

        #[Sometimes, Max(40)]
        public readonly ?string $quote_number_mask = null,

        /**
         * Whether "quote_number_mask" was present in the request at all —
         * distinguishes "not sent" (leave unchanged) from "" (reset to the
         * default mask), which a plain nullable value cannot express.
         */
        public readonly bool $quote_number_mask_provided = false,

        #[Sometimes]
        public readonly ?int $quote_number_start = null,

        public readonly bool $quote_number_start_provided = false,

        #[Sometimes, Max(5)]
        public readonly ?string $locale = null,

        #[Sometimes, Max(2), Regex('/^[A-Z]{2}$/')]
        public readonly ?string $country = null,

        #[Sometimes]
        public readonly ?string $address = null,

        #[Sometimes]
        public readonly ?string $city = null,

        #[Sometimes, Max(10)]
        public readonly ?string $postal_code = null,

        #[Sometimes, Max(20)]
        public readonly ?string $vat_id = null,

        #[Sometimes, Max(150)]
        public readonly ?string $website = null,

        #[Sometimes, Max(1000)]
        public readonly ?string $invoice_footer_text = null,

        #[Sometimes]
        public readonly ?int $overdue_reminder_days = null,

        #[Sometimes, Max(100)]
        public readonly ?string $clockify_api_key = null,

        #[Sometimes, Max(50)]
        public readonly ?string $clockify_workspace_id = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(User $user): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'name' => ['sometimes', 'string', 'max:100'],
            'surname' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)->whereNull('deleted_at')],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'password' => ['sometimes', 'string', 'min:8', 'max:255'],
            'ico' => ['sometimes', 'nullable', 'string', 'max:20'],
            'dic' => ['sometimes', 'nullable', 'string', 'max:20'],
            'is_vat_payer' => ['sometimes', 'boolean'],
            'vat_status' => ['sometimes', Rule::enum(VatStatus::class)],
            'tax_flat_rate' => ['sometimes', 'integer', 'between:0,80'],
            'default_currency' => ['sometimes', Rule::enum(Currency::class)],
            'invoice_prefix' => ['sometimes', 'string', 'max:10'],
            'invoice_number_mask' => ['sometimes', 'nullable', 'string', 'max:40', new ValidInvoiceNumberMask],
            'invoice_number_start' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:99999999'],
            'quote_number_mask' => ['sometimes', 'nullable', 'string', 'max:40', new ValidInvoiceNumberMask],
            'quote_number_start' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:99999999'],
            'locale' => ['sometimes', 'string', 'max:5'],
            'country' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'vat_id' => ['sometimes', 'nullable', 'string', 'max:20'],
            'website' => ['sometimes', 'nullable', 'string', 'max:150'],
            'invoice_footer_text' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'overdue_reminder_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'clockify_api_key' => ['sometimes', 'nullable', 'string', 'max:100'],
            'clockify_workspace_id' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            title: $request->filled('title') ? $request->string('title')->toString() : null,
            name: $request->filled('name') ? $request->string('name')->toString() : null,
            surname: $request->filled('surname') ? $request->string('surname')->toString() : null,
            email: $request->filled('email') ? $request->string('email')->toString() : null,
            phone: $request->filled('phone') ? $request->string('phone')->toString() : null,
            password: $request->filled('password') ? $request->string('password')->toString() : null,
            ico: $request->filled('ico') ? $request->string('ico')->toString() : null,
            dic: $request->filled('dic') ? $request->string('dic')->toString() : null,
            is_vat_payer: $request->filled('is_vat_payer') ? $request->boolean('is_vat_payer') : null,
            vat_status: $request->filled('vat_status') ? VatStatus::from($request->string('vat_status')->toString()) : null,
            tax_flat_rate: $request->filled('tax_flat_rate') ? $request->integer('tax_flat_rate') : null,
            default_currency: $request->filled('default_currency')
                ? Currency::from($request->string('default_currency')->toString())
                : Currency::EUR,
            invoice_prefix: $request->filled('invoice_prefix') ? $request->string('invoice_prefix')->toString() : null,
            invoice_number_mask: $request->filled('invoice_number_mask') ? $request->string('invoice_number_mask')->toString() : null,
            invoice_number_mask_provided: $request->has('invoice_number_mask'),
            invoice_number_start: $request->filled('invoice_number_start') ? $request->integer('invoice_number_start') : null,
            invoice_number_start_provided: $request->has('invoice_number_start'),
            quote_number_mask: $request->filled('quote_number_mask') ? $request->string('quote_number_mask')->toString() : null,
            quote_number_mask_provided: $request->has('quote_number_mask'),
            quote_number_start: $request->filled('quote_number_start') ? $request->integer('quote_number_start') : null,
            quote_number_start_provided: $request->has('quote_number_start'),
            locale: $request->filled('locale') ? $request->string('locale')->toString() : null,
            country: $request->filled('country') ? $request->string('country')->toString() : null,
            address: $request->filled('address') ? $request->string('address')->toString() : null,
            city: $request->filled('city') ? $request->string('city')->toString() : null,
            postal_code: $request->filled('postal_code') ? $request->string('postal_code')->toString() : null,
            vat_id: $request->filled('vat_id') ? $request->string('vat_id')->toString() : null,
            website: $request->filled('website') ? $request->string('website')->toString() : null,
            invoice_footer_text: $request->filled('invoice_footer_text') ? $request->string('invoice_footer_text')->toString() : null,
            overdue_reminder_days: $request->filled('overdue_reminder_days') ? $request->integer('overdue_reminder_days') : null,
            clockify_api_key: $request->filled('clockify_api_key') ? $request->string('clockify_api_key')->toString() : null,
            clockify_workspace_id: $request->filled('clockify_workspace_id') ? $request->string('clockify_workspace_id')->toString() : null,
        );
    }
}
