<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\DTOs;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class RegistryLookupData extends Data
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
            'country' => ['required', 'string', Rule::in(['SK', 'CZ'])],
            'ico' => ['required', 'string', 'max:20'],
        ];
    }
}
