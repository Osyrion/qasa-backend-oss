<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Services;

use App\Modules\Taxation\Domain\ValueObjects\TaxReturnResult;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders a TaxReturnResult as a PDF worksheet — same hardening as
 * InvoicePdfService/PaymentOrderPdfService (no remote fetches, no inline PHP).
 */
class TaxReturnPdfService
{
    public function generate(TaxReturnResult $result): string
    {
        $pdf = Pdf::loadView('taxation::tax-return-pdf', ['result' => $result]);

        $pdf->setOptions([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
        ]);

        $pdf->setPaper('A4', 'portrait');

        return $pdf->output();
    }

    public function filename(TaxReturnResult $result): string
    {
        return sprintf('tax-return-worksheet_%d.pdf', $result->year);
    }
}
