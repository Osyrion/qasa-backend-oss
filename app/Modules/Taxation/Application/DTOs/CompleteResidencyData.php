<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\DTOs;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * Step 2 of registration — sets tax residency, IČO and billing details once.
 * Only shape/presence is validated here; country-dependent format checks
 * (IČO/DIČ/IČ DPH) and the "already set" guard are business rules the
 * Action enforces via DomainException (they can't be expressed as static
 * per-field Laravel rules without cross-field awareness).
 */
class CompleteResidencyData extends Data
{
    public function __construct(
        public readonly string $country,
        public readonly string $ico,
        public readonly ?string $dic,
        public readonly ?string $vat_id,
        public readonly string $company_name,
        public readonly string $address,
        public readonly string $city,
        public readonly string $postal_code,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'country' => ['required', 'string', Rule::in(['SK', 'CZ'])],
            'ico' => ['required', 'string', 'max:20'],
            'dic' => ['nullable', 'string', 'max:20'],
            'vat_id' => ['nullable', 'string', 'max:20'],
            'company_name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'postal_code' => ['required', 'string', 'max:10'],
        ];
    }
}
