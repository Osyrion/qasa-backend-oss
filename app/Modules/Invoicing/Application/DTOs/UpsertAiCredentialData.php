<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\DTOs;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

class UpsertAiCredentialData extends Data
{
    public function __construct(
        #[Required, Max(255)]
        public readonly string $api_key,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'api_key' => ['required', 'string', 'max:255'],
        ];
    }
}
