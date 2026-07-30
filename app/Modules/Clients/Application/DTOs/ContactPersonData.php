<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\DTOs;

use Illuminate\Http\Request;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Data;

class ContactPersonData extends Data
{
    public function __construct(
        #[Nullable, Max(100)]
        public readonly ?string $title,

        #[Max(150)]
        public readonly string $name,

        #[Max(150)]
        public readonly string $surname,

        #[Nullable, Max(255)]
        public readonly ?string $email,

        #[Nullable, Max(30)]
        public readonly ?string $phone,

        #[Nullable, Max(100)]
        public readonly ?string $role,

        public readonly bool $is_primary = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'email' => ['nullable', 'email'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            title: $request->filled('title') ? $request->string('title')->toString() : null,
            name: $request->string('name')->toString(),
            surname: $request->string('surname')->toString(),
            email: $request->filled('email') ? $request->string('email')->toString() : null,
            phone: $request->filled('phone') ? $request->string('phone')->toString() : null,
            role: $request->filled('role') ? $request->string('role')->toString() : null,
            is_primary: $request->boolean('is_primary'),
        );
    }
}
