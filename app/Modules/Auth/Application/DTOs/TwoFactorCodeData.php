<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\DTOs;

use Illuminate\Http\Request;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

class TwoFactorCodeData extends Data
{
    public function __construct(
        #[Required, Max(20)]
        public readonly string $code,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            code: $request->string('code')->toString(),
        );
    }
}
