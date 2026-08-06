<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Domain\Models\CashDocument;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The paper half of a cash receipt: the slip both sides sign.
 *
 * Deferred out of the cash register's first cut as cosmetics, which was true
 * only while nobody handed over cash — the person paying expects something
 * to take away, and the account needs a signed copy in the folder.
 */
class CashDocumentPdfService
{
    public function generate(CashDocument $document): string
    {
        $pdf = Pdf::loadView('invoices::cash-document-pdf', [
            'document' => $document,
            'supplier' => $this->supplier($document),
        ]);

        // Same hardening as every other PDF here.
        $pdf->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false]);
        $pdf->setPaper('A4', 'portrait');

        return $pdf->output();
    }

    public function filename(CashDocument $document): string
    {
        return $document->number.'.pdf';
    }

    /**
     * Read live from the account, not frozen onto the document.
     *
     * Unlike an invoice, a cash slip carries no snapshot: it is a receipt
     * for a single moment, reprinted from the same account that issued it,
     * and freezing a copy of the company header onto every one of them would
     * be storage for a problem nobody has.
     *
     * @return array<string, mixed>
     */
    private function supplier(CashDocument $document): array
    {
        $user = $document->user;

        if ($user === null) {
            return [];
        }

        return [
            'name' => $user->supplierName(),
            'ico' => $user->ico,
            'vat_id' => $user->vat_id,
            'address' => $user->address,
            'city' => $user->city,
            'postal_code' => $user->postal_code,
        ];
    }
}
