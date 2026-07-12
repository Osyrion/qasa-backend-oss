<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Services;

use App\Modules\Invoicing\Domain\Models\PaymentOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\App;

/**
 * Landscape overview PDF of a payment batch — payer, due date, one row per
 * payment with verification state, and the batch total. Renders exclusively
 * from the frozen snapshot (payer_snapshot + items).
 */
class PaymentOrderPdfService
{
    public function generate(PaymentOrder $order): string
    {
        $order->loadMissing(['items.supplierInvoice', 'user']);

        App::setLocale($order->user->locale ?? 'sk');

        $pdf = Pdf::loadView('invoices::payment-order-pdf', [
            'order' => $order,
        ]);

        // Same hardening as InvoicePdfService: no remote fetches, no inline PHP.
        $pdf->setOptions([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
        ]);

        $pdf->setPaper('A4', 'landscape');

        return $pdf->output();
    }

    public function filename(PaymentOrder $order): string
    {
        return sprintf('payment-order_%s.pdf', $order->due_date->format('Y-m-d'));
    }
}
