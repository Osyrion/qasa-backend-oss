<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\DTOs;

use App\Modules\Shared\Enums\Currency;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

class RegisterUserData extends Data
{
    public function __construct(
        #[Required, Max(100)]
        public readonly string $name,

        #[Required, Max(100)]
        public readonly string $surname,

        #[Required, Email, Max(255)]
        public readonly string $email,

        #[Required, Min(8), Max(255)]
        public readonly string $password,

        public readonly ?string $title = null,
        public readonly Currency $default_currency = Currency::EUR,
        public readonly string $locale = 'sk',
        public readonly string $country = 'SK',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'surname' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'title' => ['nullable', 'string', 'max:100'],
            'default_currency' => ['sometimes', Rule::enum(Currency::class)],
            'locale' => ['sometimes', 'string', 'max:5'],
            'country' => ['sometimes', 'string', 'size:2'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->string('name')->toString(),
            surname: $request->string('surname')->toString(),
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            title: $request->filled('title') ? $request->string('title')->toString() : null,
            default_currency: Currency::from($request->string('default_currency', 'EUR')->toString()),
            locale: $request->string('locale', 'sk')->toString(),
            country: $request->string('country', 'SK')->toString(),
        );
    }
}
