<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\DTOs;

use App\Modules\Clients\Domain\Enums\ClientType;
use App\Modules\Shared\Enums\Currency;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class ClientData extends Data
{
    public function __construct(
        public readonly ClientType $client_type,

        #[Nullable, Max(100)]
        public readonly ?string $title,

        #[Nullable, Max(150)]
        public readonly ?string $name,

        #[Nullable, Max(150)]
        public readonly ?string $surname,

        #[Nullable, Max(200)]
        public readonly ?string $company_name,

        #[Nullable, Max(20)]
        public readonly ?string $ico,

        #[Nullable, Max(20)]
        public readonly ?string $dic,

        #[Nullable, Max(20)]
        public readonly ?string $vat_id,

        public readonly bool $is_vat_payer,

        #[Nullable, Max(255)]
        public readonly ?string $email,

        #[Nullable, Max(30)]
        public readonly ?string $phone,

        #[Nullable, Max(255)]
        public readonly ?string $address,

        #[Nullable, Max(100)]
        public readonly ?string $city,

        #[Nullable, Max(10)]
        public readonly ?string $postal_code,

        #[Min(2), Max(2)]
        public readonly string $country,

        public readonly Currency $currency,

        #[Max(5)]
        public readonly string $locale,

        #[Nullable, Max(7)]
        public readonly ?string $color,

        #[Nullable]
        public readonly ?string $note,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'client_type' => ['required', 'in:individual,self_employed,company'],
            'name' => ['required_if:client_type,individual', 'required_if:client_type,self_employed', 'nullable', 'string'],
            'surname' => ['required_if:client_type,individual', 'required_if:client_type,self_employed', 'nullable', 'string'],
            'company_name' => ['required_if:client_type,company', 'nullable', 'string'],
            'email' => ['nullable', 'email'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }
}
