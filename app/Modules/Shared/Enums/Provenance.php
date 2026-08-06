<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

/**
 * How a document or payment came to exist — AUTOMATION_FIRST_ROADMAP_PLAN.md
 * principle 4: "provenance všade... bez toho sa automatizácia nedá merať ani
 * ladiť." The phase-2 automation-rate metric (podiel dokladov/platieb bez
 * manuálneho vstupu) reads this column; nothing consumes it yet, this is the
 * groundwork phase 2 builds on.
 *
 * Manual is the fail-safe default on every write path: a call site that
 * forgets to pass a Provenance is counted as "not automated" rather than
 * silently miscounting toward the automation rate in the other direction.
 */
enum Provenance: string
{
    case Manual = 'manual';
    case EmailIn = 'email_in';
    case Api = 'api';
    case Import = 'import';
    case AutoMatched = 'auto_matched';
    case AiSuggested = 'ai_suggested';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Ručne',
            self::EmailIn => 'E-mailom',
            self::Api => 'API',
            self::Import => 'Import',
            self::AutoMatched => 'Automaticky spárované',
            self::AiSuggested => 'AI návrh',
        };
    }

    /**
     * Whether this document/payment required no manual data entry — the
     * numerator of the automation-rate metric.
     */
    public function isAutomated(): bool
    {
        return $this !== self::Manual;
    }
}
