<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\DTOs;

use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class VerifyVatData extends Data
{
    public function __construct(
        public readonly string $country,
        public readonly string $vat_id,

        /**
         * When given, a successful check stamps this client's
         * vat_verified_at — used as a grace-window fallback while VIES is
         * unreachable at invoice issuance.
         */
        #[Nullable]
        public readonly ?string $client_id = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'country' => ['required', 'string', 'size:2'],
            'vat_id' => ['required', 'string', 'max:20'],
            'client_id' => ['nullable', 'uuid'],
        ];
    }
}
