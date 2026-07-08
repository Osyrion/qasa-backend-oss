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
            'country' => ['required', 'string', 'size:2'],
            'vat_id' => ['required', 'string', 'max:20'],
        ];
    }
}
