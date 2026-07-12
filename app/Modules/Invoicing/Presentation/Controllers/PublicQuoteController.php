<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Invoicing\Application\Actions\DecideQuoteAction;
use App\Modules\Invoicing\Application\Services\QuotePdfService;
use App\Modules\Invoicing\Domain\Enums\QuoteStatus;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\Services\VatRecapCalculator;
use App\Modules\Invoicing\Domain\Services\VatRecapRow;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

/**
 * No auth context — lookup deliberately bypasses the HasUserScope global
 * scope. An unknown token 404s exactly like an unknown route.
 */
#[OA\Tag(
    name: 'Public Quote',
    description: 'Unauthenticated client-facing quote page endpoints'
)]
class PublicQuoteController extends Controller
{
    public function __construct(
        private readonly VatRecapCalculator $recapCalculator,
        private readonly QuotePdfService $pdfService,
        private readonly DecideQuoteAction $decideAction,
    ) {}

    #[OA\Get(
        path: '/api/v1/public/quotes/{token}',
        summary: 'Public quote payload for the client-facing page (no auth)',
        tags: ['Public Quote'],
        responses: [
            new OA\Response(response: 200, description: 'Quote payload'),
            new OA\Response(response: 404, description: 'Unknown token'),
        ]
    )]
    public function show(string $token): JsonResponse
    {
        $quote = Quote::withoutGlobalScope('user')
            ->where('public_token', $token)
            ->firstOrFail();

        $quote->loadMissing('items');

        if ($quote->public_first_viewed_at === null) {
            $quote->forceFill(['public_first_viewed_at' => now()])->save();
        }

        $quote->increment('public_view_count');

        $effectiveStatus = $quote->effectiveStatus();

        return response()->json([
            'quote_number' => $quote->quote_number,
            'issued_at' => $quote->issued_at->toDateString(),
            'valid_until' => $quote->valid_until?->toDateString(),
            'currency' => $quote->currency->value,
            'supplier' => $quote->supplier_snapshot,
            'client' => $quote->client_snapshot,
            'items' => $quote->items->map(static fn ($item): array => [
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
            ], $this->recapCalculator->recapForQuote($quote)),
            'discount_percent' => $quote->discount_percent !== null ? (float) $quote->discount_percent : null,
            'discount_amount' => (float) $quote->discount_amount,
            'subtotal' => (float) $quote->subtotal,
            'vat_amount' => (float) $quote->vat_amount,
            'total' => (float) $quote->total,
            'effective_status' => $effectiveStatus->value,
            'can_decide' => $effectiveStatus === QuoteStatus::Sent,
        ]);
    }

    #[OA\Get(
        path: '/api/v1/public/quotes/{token}/pdf',
        summary: 'Public quote PDF download (no auth)',
        tags: ['Public Quote'],
        responses: [
            new OA\Response(response: 200, description: 'PDF file', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 404, description: 'Unknown token'),
        ]
    )]
    public function pdf(string $token): Response
    {
        $quote = Quote::withoutGlobalScope('user')
            ->where('public_token', $token)
            ->firstOrFail();

        $pdf = $this->pdfService->generate($quote);
        $filename = $this->pdfService->filename($quote);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

    #[OA\Post(
        path: '/api/v1/public/quotes/{token}/accept',
        summary: 'Client accepts the quote (no auth, one-shot)',
        tags: ['Public Quote'],
        responses: [
            new OA\Response(response: 200, description: 'Quote accepted'),
            new OA\Response(response: 404, description: 'Unknown token'),
            new OA\Response(response: 422, description: 'Quote expired or already decided'),
        ]
    )]
    public function accept(Request $request, string $token): JsonResponse
    {
        return $this->decide($request, $token, accept: true);
    }

    #[OA\Post(
        path: '/api/v1/public/quotes/{token}/reject',
        summary: 'Client rejects the quote (no auth, one-shot)',
        tags: ['Public Quote'],
        responses: [
            new OA\Response(response: 200, description: 'Quote rejected'),
            new OA\Response(response: 404, description: 'Unknown token'),
            new OA\Response(response: 422, description: 'Quote expired or already decided'),
        ]
    )]
    public function reject(Request $request, string $token): JsonResponse
    {
        return $this->decide($request, $token, accept: false);
    }

    private function decide(Request $request, string $token, bool $accept): JsonResponse
    {
        $quote = Quote::withoutGlobalScope('user')
            ->where('public_token', $token)
            ->firstOrFail();

        $request->validate([
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $note = $request->filled('decision_note') ? $request->string('decision_note')->toString() : null;

        try {
            $updated = $accept
                ? $this->decideAction->accept($quote, $note, $request->ip())
                : $this->decideAction->reject($quote, $note, $request->ip());

            return response()->json([
                'status' => $updated->status,
                'accepted_at' => $updated->accepted_at?->toISOString(),
                'rejected_at' => $updated->rejected_at?->toISOString(),
            ]);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
