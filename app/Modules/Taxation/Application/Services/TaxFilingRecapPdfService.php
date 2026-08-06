<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Taxation\Domain\Models\TaxFiling;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The human-readable half of a filing: every value the archived document
 * actually carries, plus the builder's own stated assumptions.
 *
 * SK_VAT_FILING_EDANE_PLAN.md left this out of v1, which meant the only way
 * to check a VAT return before submitting it was to read raw XML. On a
 * feature where a wrong figure is a fine, that is the wrong place to save
 * effort.
 */
class TaxFilingRecapPdfService
{
    public function __construct(
        private readonly TaxFilingRecapService $recap,
    ) {}

    /**
     * @param  list<string>  $assumptions
     */
    public function generate(TaxFiling $filing, array $assumptions = []): string
    {
        $pdf = Pdf::loadView('taxation::tax-filing-recap-pdf', [
            'filing' => $filing,
            'rows' => $this->recap->rows($filing),
            'assumptions' => $assumptions,
            'period' => $this->period($filing),
        ]);

        // Same hardening as every other PDF here — no remote fetches, no
        // inline PHP.
        $pdf->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false]);
        $pdf->setPaper('A4', 'portrait');

        return $pdf->output();
    }

    public function filename(TaxFiling $filing): string
    {
        return sprintf('%s_%s.pdf', $filing->type->value, $this->period($filing));
    }

    private function period(TaxFiling $filing): string
    {
        if ($filing->period_quarter !== null) {
            return sprintf('%d-Q%d', $filing->period_year, $filing->period_quarter);
        }

        if ($filing->period_month !== null) {
            return sprintf('%d-%02d', $filing->period_year, $filing->period_month);
        }

        return (string) $filing->period_year;
    }
}
