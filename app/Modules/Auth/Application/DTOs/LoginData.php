<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\DTOs;

use Illuminate\Http\Request;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

class LoginData extends Data
{
    public function __construct(
        #[Required, Email, Max(255)]
        public readonly string $email,

        #[Required, Max(255)]
        public readonly string $password,

        public readonly bool $remember = false,
        public readonly ?string $device_name = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            remember: $request->boolean('remember', false),
            device_name: $request->filled('device_name') ? $request->string('device_name')->toString() : null,
        );
    }
}
