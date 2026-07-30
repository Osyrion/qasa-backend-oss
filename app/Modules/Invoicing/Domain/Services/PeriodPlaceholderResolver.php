<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Domain\Services;

use Carbon\CarbonImmutable;

/**
 * Resolves billing-period placeholders in template texts at generation time.
 *
 * Supported: {BOM} first day of month (j.n.Y), {EOM} last day of month (j.n.Y),
 * {MONTH} m/Y (e.g. 05/2026), {YEAR} Y. Formats are fixed numeric dates —
 * locale-independent; drafts remain editable.
 */
final class PeriodPlaceholderResolver
{
    public function resolve(?string $text, CarbonImmutable $periodDate): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return strtr($text, [
            '{BOM}' => $periodDate->startOfMonth()->format('j.n.Y'),
            '{EOM}' => $periodDate->endOfMonth()->format('j.n.Y'),
            '{MONTH}' => $periodDate->format('m/Y'),
            '{YEAR}' => $periodDate->format('Y'),
        ]);
    }
}
