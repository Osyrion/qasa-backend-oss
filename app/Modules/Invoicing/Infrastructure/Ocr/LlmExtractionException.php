<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Ocr;

use App\Modules\Shared\Exceptions\ExpectedIntegrationFailure;
use RuntimeException;

/**
 * Thrown by LlmFieldExtractor for any failure (timeout, malformed response,
 * rate limit, auth error) — always caught by the caller (ProcessInboxItemJob)
 * and turned into a regex fallback. isAuthError distinguishes an invalid API
 * key (401/403) so a BYOK path can flag byok_key_invalid without touching
 * the platform quota.
 */
final class LlmExtractionException extends RuntimeException implements ExpectedIntegrationFailure
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

    /**
     * A BYOK key the provider rejects is the account's own to replace, and
     * the extraction falls back to regex either way. A timeout or a malformed
     * response is worth seeing.
     */
    public function isExpected(): bool
    {
        return $this->authError;
    }
}
