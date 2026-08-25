<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\DTOs;

use App\Modules\Auth\Domain\Rules\EmailAvailable;
use App\Modules\Shared\Domain\Rules\DisposableEmailBlocked;
use App\Modules\Shared\Enums\Currency;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\LaravelData\Attributes\Validation\Accepted;
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

        // Art. 7(1) — registration is the only moment the backend can prove
        // consent to the terms/privacy policy, so it is required, not
        // opt-in. RegisterUserAction stamps the current config('gdpr.terms_version')
        // against it rather than trusting a version from the request.
        #[Required, Accepted]
        public readonly bool $accepted_terms = false,

        public readonly ?string $title = null,
        public readonly Currency $default_currency = Currency::EUR,
        public readonly string $locale = 'sk',

        // Cloudflare Turnstile, same field the waitlist form posts. Nullable
        // because the check is a no-op when services.turnstile.enabled is
        // off — a deployment without Turnstile configured keeps working.
        public readonly ?string $turnstile_token = null,
    ) {}

    /**
     * No phone field, deliberately.
     *
     * Verifying a number is a step *after* registration
     * (POST /auth/phone/send-code), for two reasons that both point the same
     * way: a number already verified elsewhere would have to fail the
     * request, which is the one thing this feature must never do to
     * registration — and validating it here would turn a public endpoint
     * into an oracle for which numbers hold accounts.
     */

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'surname' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', new DisposableEmailBlocked, new EmailAvailable],
            'password' => ['required', 'string', 'max:255', Password::defaults()],
            'accepted_terms' => ['required', 'accepted'],
            'title' => ['nullable', 'string', 'max:100'],
            'default_currency' => ['sometimes', Rule::enum(Currency::class)],
            'locale' => ['sometimes', 'string', 'max:5'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'turnstile_token' => ['nullable', 'string'],
            // Read straight off the request by AuthController::register
            // rather than carried on this DTO — it decides whether the
            // endpoint answers at all, which is settled before any of
            // these fields matter.
            'invitation_token' => ['nullable', 'string'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            name: $request->string('name')->toString(),
            surname: $request->string('surname')->toString(),
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            accepted_terms: $request->boolean('accepted_terms'),
            title: $request->filled('title') ? $request->string('title')->toString() : null,
            default_currency: Currency::from($request->string('default_currency', 'EUR')->toString()),
            locale: $request->string('locale', self::preferredLocale($request))->toString(),
            turnstile_token: $request->filled('turnstile_token') ? $request->string('turnstile_token')->toString() : null,
        );
    }

    /**
     * Accept-Language fallback for the "locale" field when the client omits
     * it — residency and locale are independent axes (a SK company may run
     * the UI in English), so this is only ever a starting default the user
     * can change later, never derived from tax residency.
     */
    private static function preferredLocale(Request $request): string
    {
        $available = config('qasa.locales.available', [config('app.locale')]);

        return $request->getPreferredLanguage($available) ?? (string) config('app.fallback_locale');
    }
}
