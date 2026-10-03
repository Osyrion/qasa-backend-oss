<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Clients\Application\Contracts\ClientPortalDirectory;
use App\Modules\Clients\Domain\ValueObjects\PortalClient;
use App\Modules\Invoicing\Application\Services\InvoicePdfService;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Enums\QuoteStatus;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Shared\Support\ContentDisposition;
use App\Modules\Shared\Support\Decimal;
use App\Modules\Shared\Support\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

/**
 * The client's own view of what they owe — one durable link instead of one
 * link per document.
 *
 * Two narrowings, both required. BindClientPortalTenant binds the *account*,
 * which is what makes anything visible at all under the row-level policy;
 * clientFor() then narrows to the one *client* holding the token. The policy
 * cannot do the second — it does not know about clients — so a missing
 * client_id filter here would hand the whole account's invoices to whoever
 * holds one client's link.
 *
 * Read-only by construction: there is no write endpoint to omit a check on.
 * Quotes are listed here but never decided here — accept/reject keeps its own
 * per-document link, because one decision reachable two ways is a decision
 * whose audit trail depends on which way it was taken.
 */
#[OA\Tag(name: 'Client Portal', description: 'Unauthenticated client-facing portal (all invoices of one client)')]
class ClientPortalController extends Controller
{
    public function __construct(
        private readonly InvoicePdfService $pdfService,
        private readonly ClientPortalDirectory $clients,
    ) {}

    #[OA\Get(
        path: '/api/v1/portal/{token}',
        summary: 'Portal summary for the client holding this link (no auth)',
        tags: ['Client Portal'],
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Client name and outstanding summary'),
            new OA\Response(response: 404, description: 'Unknown or revoked link'),
        ]
    )]
    public function show(string $token): JsonResponse
    {
        $client = $this->clientFor($token);

        $invoices = $this->visibleInvoices($client)->get();

        $outstanding = Decimal::sum(
            $invoices->map(static fn (Invoice $invoice): string => (string) $invoice->balance()),
        );

        return response()->json([
            'client' => [
                'name' => $client->profile->name,
                'email' => $client->profile->email,
            ],
            'supplier' => [
                'name' => $client->supplier?->name,
            ],
            'summary' => [
                'invoice_count' => $invoices->count(),
                'overdue_count' => $invoices->filter(
                    static fn (Invoice $invoice): bool => $invoice->balance() > 0.0 && $invoice->due_at->isPast(),
                )->count(),
                'outstanding_total' => Decimal::money($outstanding),
                'currency' => $invoices->first()?->currency->value,
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/portal/{token}/invoices',
        summary: 'Invoices issued to the client holding this link (no auth)',
        tags: ['Client Portal'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated invoices'),
            new OA\Response(response: 404, description: 'Unknown or revoked link'),
        ]
    )]
    public function invoices(Request $request, string $token): JsonResponse
    {
        $client = $this->clientFor($token);

        $paginator = Pagination::of(
            $this->visibleInvoices($client)->orderByDesc('issued_at'),
            $request,
        );

        return response()->json([
            'data' => array_map(
                fn (Invoice $invoice): array => [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'type' => $invoice->type->value,
                    'status' => $invoice->status,
                    'issued_at' => $invoice->issued_at->toDateString(),
                    'due_at' => $invoice->due_at->toDateString(),
                    'currency' => $invoice->currency->value,
                    'total' => (string) $invoice->total,
                    'balance' => Decimal::money($invoice->balance()),
                    'is_overdue' => $invoice->balance() > 0.0 && $invoice->due_at->isPast(),
                ],
                $paginator->items(),
            ),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/portal/{token}/invoices/{invoice}/pdf',
        summary: 'PDF of one invoice belonging to the client holding this link (no auth)',
        tags: ['Client Portal'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'invoice', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'PDF file', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 404, description: 'Unknown link, or an invoice belonging to somebody else'),
        ]
    )]
    public function pdf(string $token, string $invoice): Response
    {
        $client = $this->clientFor($token);

        // Resolved through the client's own invoices, never by id alone —
        // otherwise one client's link would fetch another client's PDF from
        // the same account, which the row-level policy happily permits.
        $model = $this->visibleInvoices($client)->where('id', $invoice)->firstOrFail();

        return response($this->pdfService->generate($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ContentDisposition::attachment($this->pdfService->filename($model)),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/portal/{token}/quotes',
        summary: 'Quotes issued to the client holding this link (no auth, read-only)',
        description: 'Listing only. Accepting or rejecting a quote stays on its own per-document link — one decision must not have two routes to it.',
        tags: ['Client Portal'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated quotes'),
            new OA\Response(response: 404, description: 'Unknown or revoked link'),
        ]
    )]
    public function quotes(Request $request, string $token): JsonResponse
    {
        $client = $this->clientFor($token);

        $paginator = Pagination::of(
            Quote::withoutGlobalScope('user')
                ->where('client_id', $client->id)
                ->where('status', '!=', QuoteStatus::Draft->value)
                ->orderByDesc('issued_at'),
            $request,
        );

        return response()->json([
            'data' => array_map(
                static fn (Quote $quote): array => [
                    'id' => $quote->id,
                    'quote_number' => $quote->quote_number,
                    'status' => $quote->status,
                    'issued_at' => $quote->issued_at->toDateString(),
                    'valid_until' => $quote->valid_until?->toDateString(),
                    'currency' => $quote->currency->value,
                    'total' => (string) $quote->total,
                ],
                $paginator->items(),
            ),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    private function clientFor(string $token): PortalClient
    {
        return $this->clients->requireByToken($token);
    }

    /**
     * @return Builder<Invoice>
     */
    private function visibleInvoices(PortalClient $client): Builder
    {
        // Drafts have no number and were never sent — showing them would be
        // showing the client something that does not exist yet.
        return Invoice::withoutGlobalScope('user')
            ->where('client_id', $client->id)
            ->where('status', '!=', InvoiceStatus::Draft->value);
    }
}
