<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\DTOs;

use Spatie\LaravelData\Data;

class SendPhoneVerificationData extends Data
{
    public function __construct(
        public readonly string $phone,
    ) {}

    /**
     * E.164 and nothing else. No country code is inferred from the account's
     * residency: guessing wrong sends the code to a stranger's handset, and
     * the caller always knows the full number it means.
     *
     * PhoneAvailable is not applied here — the send path checks it against
     * the calling account (which its own verified number must not block),
     * and a validation rule has no access to that. SendPhoneVerificationCodeAction
     * does it instead.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
        ];
    }

    /**
     * Strip the separators people actually type — "+421 900 123 456",
     * "+420-777-123-456" — before the pattern above sees them. Runs ahead of
     * validation, so what gets validated is what gets stored.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        if (isset($properties['phone']) && is_string($properties['phone'])) {
            $properties['phone'] = self::normalise($properties['phone']);
        }

        return $properties;
    }

    public static function normalise(string $phone): string
    {
        return (string) preg_replace('/[\s\-().]/', '', trim($phone));
    }
}
