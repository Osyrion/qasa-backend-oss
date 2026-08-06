<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Services;

/**
 * Phase 3 Part C (docs/plans/MCP_AI_ASSISTANT_EXPANSION_PLAN.md) — strips
 * the specific classes of sensitive data an LLM prompt never needs before
 * any free text a user typed (an Expense description, an invoice note...)
 * is sent to an outbound AI call: full IBANs and SK/CZ birth numbers, the
 * plan's own named examples. Deliberately narrow — this is not a general
 * PII scrubber, just the two patterns concrete enough to redact without
 * false-positiving on ordinary business text (an invoice number or amount
 * looks nothing like either pattern).
 */
final class AiPayloadSanitizer
{
    /**
     * ISO 13616 IBAN: 2 letters + 2 check digits + up to 30 alphanumerics.
     */
    private const string IBAN_PATTERN = '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/';

    /**
     * SK/CZ birth number (rodné číslo): YYMMDD/XXX or YYMMDD/XXXX, slash
     * optional.
     */
    private const string BIRTH_NUMBER_PATTERN = '/\b\d{6}\/?\d{3,4}\b/';

    public function sanitize(string $text): string
    {
        $text = preg_replace(self::IBAN_PATTERN, '[REDACTED_IBAN]', $text) ?? $text;
        $text = preg_replace(self::BIRTH_NUMBER_PATTERN, '[REDACTED_ID]', $text) ?? $text;

        return $text;
    }
}
