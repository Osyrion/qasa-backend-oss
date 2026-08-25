<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\DTOs;

use Illuminate\Http\Request;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

class WaitlistSignupData extends Data
{
    public function __construct(
        #[Max(255)]
        public readonly string $email,

        public readonly string $locale,

        #[Max(50)]
        public readonly ?string $pageVariant,

        // Anti-spam trap: left blank by real visitors, only a bot fills it.
        // Deliberately not validated as "prohibited" — the Action swallows a
        // filled honeypot silently instead of surfacing a 422 that would tip
        // a bot off to which field to leave empty.
        public readonly ?string $honeypot,

        // Cloudflare Turnstile response token. Only checked by the Action
        // when services.turnstile.enabled — see TurnstileVerifier.
        public readonly ?string $turnstileToken,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'locale' => ['required', 'string', 'in:cs,sk,en'],
            'page_variant' => ['nullable', 'string', 'max:50'],
            'honeypot' => ['nullable', 'string'],
            'turnstile_token' => ['nullable', 'string'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            email: $request->string('email')->toString(),
            locale: $request->string('locale')->toString(),
            pageVariant: $request->filled('page_variant') ? $request->string('page_variant')->toString() : null,
            honeypot: $request->filled('honeypot') ? $request->string('honeypot')->toString() : null,
            turnstileToken: $request->filled('turnstile_token') ? $request->string('turnstile_token')->toString() : null,
        );
    }
}
