<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\DTOs;

use Spatie\LaravelData\Data;

class LookupCompanyData extends Data
{
    public function __construct(
        public readonly string $country,
        public readonly string $ico,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'country' => ['required', 'string', 'in:CZ,SK'],
            'ico' => ['required', 'string', 'max:20'],
        ];
    }
}
