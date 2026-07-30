<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\DTOs;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

class DeleteAccountData extends Data
{
    public function __construct(
        public readonly ?string $password = null,
        public readonly ?string $confirmation = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'password' => ['nullable', 'string'],
            'confirmation' => ['nullable', 'string'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            password: $request->filled('password') ? $request->string('password')->toString() : null,
            confirmation: $request->filled('confirmation') ? $request->string('confirmation')->toString() : null,
        );
    }
}
