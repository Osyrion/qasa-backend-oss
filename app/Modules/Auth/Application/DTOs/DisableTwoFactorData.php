<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\DTOs;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

class DisableTwoFactorData extends Data
{
    public function __construct(
        public readonly ?string $password = null,
        public readonly ?string $code = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            // Nullable: Google-only accounts have no password to submit —
            // DisableTwoFactorAction enforces it only when the account has one.
            'password' => ['nullable', 'string'],
            'code' => ['required', 'string', 'max:20'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            password: $request->filled('password') ? $request->string('password')->toString() : null,
            code: $request->filled('code') ? $request->string('code')->toString() : null,
        );
    }
}
