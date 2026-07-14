<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use App\Modules\Invoicing\Domain\Enums\SupplierVatRegime;
use App\Modules\Invoicing\Domain\Rules\VatRateInCatalog;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class SupplierInvoiceData extends Data
{
    /**
     * @param  list<SupplierInvoiceVatLineData>  $vat_lines
     */
    public function __construct(
        public readonly string $client_id,
        public readonly string $supplier_invoice_number,
        public readonly string $issued_at,
        public readonly Currency $currency,
        public readonly array $vat_lines,

        #[Nullable]
        public readonly ?string $taxable_supply_at = null,

        #[Nullable]
        public readonly ?string $due_at = null,

        #[Nullable]
        public readonly ?string $received_at = null,

        #[Nullable]
        public readonly ?float $exchange_rate = null,

        #[Nullable]
        public readonly ?string $variable_symbol = null,

        #[Nullable]
        public readonly ?string $note = null,

        public readonly SupplierVatRegime $vat_regime = SupplierVatRegime::Domestic,

        #[Nullable]
        public readonly ?string $vendor_account_number = null,

        #[Nullable]
        public readonly ?string $vendor_bank_code = null,

        #[Nullable]
        public readonly ?string $vendor_iban = null,

        #[Nullable]
        public readonly ?string $vendor_bic = null,
    ) {}

    /**
     * Import is self-assessed at whatever rate customs determined — it may
     * not match the tenant's own catalog, so only domestic and
     * eu_reverse_charge (self-assessed at our own domestic rate) are
     * checked against it.
     *
     * @return array<string, mixed>
     */
    public static function rules(?string $userId = null, ?string $country = null, ?string $onDate = null, ?string $vatRegime = null): array
    {
        $isImport = $vatRegime === SupplierVatRegime::Import->value;

        return [
            'client_id' => ['required', 'uuid'],
            'supplier_invoice_number' => ['required', 'string', 'max:60'],
            'issued_at' => ['required', 'date'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'taxable_supply_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'received_at' => ['nullable', 'date'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'variable_symbol' => ['nullable', 'string', 'regex:/^\d{1,10}$/'],
            'note' => ['nullable', 'string', 'max:2000'],
            // Vendor payment account: domestic pair and IBAN pair are each
            // all-or-nothing; BIC only makes sense alongside an IBAN.
            'vendor_account_number' => ['nullable', 'string', 'regex:/^(\d{1,6}-)?\d{2,10}$/', 'required_with:vendor_bank_code'],
            'vendor_bank_code' => ['nullable', 'digits:4', 'required_with:vendor_account_number'],
            'vendor_iban' => ['nullable', 'string', 'max:34', 'regex:/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', 'required_with:vendor_bic'],
            'vendor_bic' => ['nullable', 'string', 'regex:/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/'],
            'vat_regime' => ['sometimes', Rule::enum(SupplierVatRegime::class)],
            'vat_lines' => ['required', 'array', 'min:1'],
            'vat_lines.*.vat_rate' => [
                'required', 'numeric', 'min:0', 'max:100',
                ...($userId !== null && $country !== null && ! $isImport ? [new VatRateInCatalog($userId, $country, $onDate)] : []),
            ],
            'vat_lines.*.base' => ['required', 'numeric', 'min:0'],
            'vat_lines.*.vat_amount' => ['required', 'numeric', 'min:0'],
            'vat_lines.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            client_id: $request->string('client_id')->toString(),
            supplier_invoice_number: $request->string('supplier_invoice_number')->toString(),
            issued_at: $request->string('issued_at')->toString(),
            currency: Currency::from($request->string('currency')->toString()),
            vat_lines: array_values(array_map(
                fn (array $line): SupplierInvoiceVatLineData => SupplierInvoiceVatLineData::fromArray($line),
                $request->array('vat_lines'),
            )),
            taxable_supply_at: $request->filled('taxable_supply_at') ? $request->string('taxable_supply_at')->toString() : null,
            due_at: $request->filled('due_at') ? $request->string('due_at')->toString() : null,
            received_at: $request->filled('received_at') ? $request->string('received_at')->toString() : null,
            exchange_rate: $request->filled('exchange_rate') ? (float) $request->input('exchange_rate') : null,
            variable_symbol: $request->filled('variable_symbol') ? $request->string('variable_symbol')->toString() : null,
            note: $request->filled('note') ? $request->string('note')->toString() : null,
            vat_regime: $request->filled('vat_regime')
                ? SupplierVatRegime::from($request->string('vat_regime')->toString())
                : SupplierVatRegime::Domestic,
            vendor_account_number: $request->filled('vendor_account_number') ? $request->string('vendor_account_number')->toString() : null,
            vendor_bank_code: $request->filled('vendor_bank_code') ? $request->string('vendor_bank_code')->toString() : null,
            vendor_iban: $request->filled('vendor_iban') ? strtoupper(str_replace(' ', '', $request->string('vendor_iban')->toString())) : null,
            vendor_bic: $request->filled('vendor_bic') ? strtoupper($request->string('vendor_bic')->toString()) : null,
        );
    }

    public function hasVendorAccount(): bool
    {
        return ($this->vendor_account_number !== null && $this->vendor_bank_code !== null)
            || $this->vendor_iban !== null;
    }
}
