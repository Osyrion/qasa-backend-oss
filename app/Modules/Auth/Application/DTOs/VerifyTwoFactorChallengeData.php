<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\DTOs;

use Illuminate\Http\Request;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

class VerifyTwoFactorChallengeData extends Data
{
    public function __construct(
        #[Required, Max(255)]
        public readonly string $challenge_token,

        #[Required, Max(20)]
        public readonly string $code,

        public readonly ?string $device_name = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'challenge_token' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            challenge_token: $request->string('challenge_token')->toString(),
            code: $request->string('code')->toString(),
            device_name: $request->filled('device_name') ? $request->string('device_name')->toString() : null,
        );
    }
}
