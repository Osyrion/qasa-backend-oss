<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ocr;

use RuntimeException;

/**
 * Thrown by LlmFieldExtractor for any failure (timeout, malformed response,
 * rate limit, auth error) — always caught by the caller (ProcessInboxItemJob)
 * and turned into a regex fallback. isAuthError distinguishes an invalid API
 * key (401/403) so a BYOK path can flag byok_key_invalid without touching
 * the platform quota.
 */
final class LlmExtractionException extends RuntimeException
{
    private function __construct(string $message, private readonly bool $authError)
    {
        parent::__construct($message);
    }

    public static function because(string $message): self
    {
        return new self($message, authError: false);
    }

    public static function unauthorized(string $message): self
    {
        return new self($message, authError: true);
    }

    public function isAuthError(): bool
    {
        return $this->authError;
    }
}
