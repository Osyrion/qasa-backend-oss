<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\DTOs;

use Spatie\LaravelData\Data;

class VerifyVatData extends Data
{
    public function __construct(
        public readonly string $country,
        public readonly string $vat_id,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            // Both are interpolated into the VIES request path, so restrict
            // them to a safe charset (letters / alphanumerics, no metacharacters).
            'country' => ['required', 'string', 'size:2', 'alpha'],
            'vat_id' => ['required', 'string', 'regex:/^[A-Za-z0-9]{2,20}$/'],
        ];
    }
}
