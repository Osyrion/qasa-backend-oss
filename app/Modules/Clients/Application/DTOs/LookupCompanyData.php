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
            // Digits only: the IČO is interpolated into the ARES/RPO request
            // path, so restrict it to a safe charset (no URL metacharacters).
            'ico' => ['required', 'string', 'regex:/^[0-9]{6,12}$/'],
        ];
    }
}
