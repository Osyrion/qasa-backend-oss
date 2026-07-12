<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Invoicing\Application\Services\InvoicePdfService;
use App\Modules\Invoicing\Application\Services\PaymentQrService;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Events\InvoiceViewed;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Services\VatRecapCalculator;
use App\Modules\Invoicing\Domain\Services\VatRecapRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

/**
 * No auth context here — lookup deliberately bypasses the HasUserScope
 * global scope. An unknown token must 404 exactly like an unknown route,
 * so no distinction is made between "wrong token" and "no such invoice".
 */
#[OA\Tag(
    name: 'Public Invoice',
    description: 'Unauthenticated client-facing invoice page endpoints'
)]
class PublicInvoiceController extends Controller
{
    public function __construct(
        private readonly VatRecapCalculator $recapCalculator,
        private readonly PaymentQrService $paymentQrService,
        private readonly InvoicePdfService $pdfService,
    ) {}

    #[OA\Get(
        path: '/api/v1/public/invoices/{token}',
        summary: 'Public invoice payload for the client-facing page (no auth)',
        tags: ['Public Invoice'],
        parameters: [
            new OA\Parameter(
                name: 'token',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Invoice payload'),
            new OA\Response(response: 404, description: 'Unknown token'),
            new OA\Response(response: 429, description: 'Too many requests'),
        ]
    )]
    public function show(string $token): JsonResponse
    {
        $invoice = Invoice::withoutGlobalScope('user')
            ->where('public_token', $token)
            ->firstOrFail();

        $invoice->loadMissing(['items']);

        if ($invoice->public_first_viewed_at === null) {
            $invoice->forceFill(['public_first_viewed_at' => now()])->save();

            event(new InvoiceViewed($invoice));
        }

        $invoice->increment('public_view_count');

        $balance = $invoice->balance();
        $qrDataUri = $balance > 0 ? $this->paymentQrService->dataUri($invoice, $balance) : null;
        $bank = $invoice->bank_account_snapshot;

        return response()->json([
            'invoice_number' => $invoice->invoice_number,
            'type' => $invoice->type?->label(),
            'issued_at' => $invoice->issued_at?->toDateString(),
            'taxable_supply_at' => $invoice->taxable_supply_at?->toDateString(),
            'due_at' => $invoice->due_at?->toDateString(),
            'variable_symbol' => $invoice->variable_symbol,
            'currency' => $invoice->currency?->value,
            'supplier' => $invoice->supplier_snapshot,
            'client' => $invoice->client_snapshot,
            'items' => $invoice->items->map(static fn ($item): array => [
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
                'unit_price' => (float) $item->unit_price,
                'vat_rate' => (float) $item->vat_rate,
                'vat_amount' => (float) $item->vat_amount,
                'total_excl_vat' => (float) $item->total_excl_vat,
                'total_incl_vat' => (float) $item->total_incl_vat,
            ])->all(),
            'vat_recap' => array_map(static fn (VatRecapRow $row): array => [
                'rate' => $row->rate,
                'base' => $row->base,
                'vat' => $row->vat,
                'total' => $row->total,
            ], $this->recapCalculator->recap($invoice)),
            'discount_percent' => $invoice->discount_percent !== null ? (float) $invoice->discount_percent : null,
            'discount_amount' => (float) $invoice->discount_amount,
            'subtotal' => (float) $invoice->subtotal,
            'vat_amount' => (float) $invoice->vat_amount,
            'total' => (float) $invoice->total,
            'payment' => [
                'balance' => $balance,
                'is_paid' => $balance <= 0,
                'iban' => $bank['iban'] ?? null,
                'bic' => $bank['bic'] ?? null,
                'account_number' => $bank['account_number'] ?? null,
                'variable_symbol' => $invoice->variable_symbol,
                'qr_svg' => $qrDataUri,
            ],
            'public_status' => $this->publicStatus($invoice),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/public/invoices/{token}/pdf',
        summary: 'Public invoice PDF download (no auth)',
        tags: ['Public Invoice'],
        parameters: [
            new OA\Parameter(
                name: 'token',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'PDF file',
                content: new OA\MediaType(mediaType: 'application/pdf')
            ),
            new OA\Response(response: 404, description: 'Unknown token'),
            new OA\Response(response: 429, description: 'Too many requests'),
        ]
    )]
    public function pdf(string $token): Response
    {
        $invoice = Invoice::withoutGlobalScope('user')
            ->where('public_token', $token)
            ->firstOrFail();

        $pdf = $this->pdfService->generate($invoice);
        $filename = $this->pdfService->filename($invoice);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Simplified status the public page renders — drafts never reach here
     * (no draft ever carries a public_token).
     */
    private function publicStatus(Invoice $invoice): string
    {
        if ($invoice->isCancelled()) {
            return 'cancelled';
        }

        if ($invoice->statusEnum() === InvoiceStatus::Credited) {
            return 'credited';
        }

        $balance = $invoice->balance();

        return match (true) {
            $balance <= 0 => 'paid',
            $balance < (float) $invoice->total => 'partially_paid',
            default => 'unpaid',
        };
    }
}
