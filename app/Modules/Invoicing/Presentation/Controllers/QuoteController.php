<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Actions\AddQuoteItemAction;
use App\Modules\Invoicing\Application\Actions\ConvertQuoteToInvoiceAction;
use App\Modules\Invoicing\Application\Actions\ConvertQuoteToOrderAction;
use App\Modules\Invoicing\Application\Actions\CreateQuoteAction;
use App\Modules\Invoicing\Application\Actions\CreateQuotePublicLinkAction;
use App\Modules\Invoicing\Application\Actions\RemoveQuoteItemAction;
use App\Modules\Invoicing\Application\Actions\RevokeQuotePublicLinkAction;
use App\Modules\Invoicing\Application\Actions\SendQuoteEmailAction;
use App\Modules\Invoicing\Application\Actions\UpdateQuoteAction;
use App\Modules\Invoicing\Application\Actions\UpdateQuoteStatusAction;
use App\Modules\Invoicing\Application\Contracts\QuoteRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\QuoteData;
use App\Modules\Invoicing\Application\DTOs\QuoteItemData;
use App\Modules\Invoicing\Application\DTOs\SendInvoiceEmailData;
use App\Modules\Invoicing\Application\Services\QuotePdfService;
use App\Modules\Invoicing\Domain\Enums\QuoteStatus;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\Models\QuoteItem;
use App\Modules\Invoicing\Presentation\Resources\InvoiceResource;
use App\Modules\Invoicing\Presentation\Resources\QuoteItemResource;
use App\Modules\Invoicing\Presentation\Resources\QuoteResource;
use App\Modules\Orders\Presentation\Resources\OrderResource;
use App\Modules\Shared\Support\Pagination;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Quotes',
    description: 'Quote (cenová ponuka) management endpoints'
)]
class QuoteController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly QuoteRepositoryInterface $repository,
        private readonly CreateQuoteAction $createAction,
        private readonly UpdateQuoteAction $updateAction,
        private readonly AddQuoteItemAction $addItemAction,
        private readonly RemoveQuoteItemAction $removeItemAction,
        private readonly SendQuoteEmailAction $sendEmailAction,
        private readonly UpdateQuoteStatusAction $updateStatusAction,
        private readonly CreateQuotePublicLinkAction $createPublicLinkAction,
        private readonly RevokeQuotePublicLinkAction $revokePublicLinkAction,
        private readonly ConvertQuoteToInvoiceAction $convertToInvoiceAction,
        private readonly ConvertQuoteToOrderAction $convertToOrderAction,
        private readonly QuotePdfService $pdfService,
    ) {
        $this->authorizeResource(Quote::class, 'quote');
    }

    #[OA\Get(
        path: '/api/v1/quotes',
        summary: 'List quotes',
        security: [['sanctum' => []]],
        tags: ['Quotes'],
        responses: [new OA\Response(response: 200, description: 'List of quotes')]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $quotes = $this->repository->paginate(
            perPage: Pagination::perPage($request),
            filters: $request->only(['status', 'client_id', 'date_from', 'date_to', 'sort', 'direction']),
        );

        return QuoteResource::collection($quotes);
    }

    #[OA\Get(
        path: '/api/v1/quotes/{id}',
        summary: 'Get quote details',
        security: [['sanctum' => []]],
        tags: ['Quotes'],
        responses: [
            new OA\Response(response: 200, description: 'Quote details'),
            new OA\Response(response: 404, description: 'Quote not found'),
        ]
    )]
    public function show(Quote $quote): QuoteResource
    {
        $quote->load(['client', 'items']);

        return QuoteResource::make($quote);
    }

    #[OA\Post(
        path: '/api/v1/quotes',
        summary: 'Create quote',
        security: [['sanctum' => []]],
        tags: ['Quotes'],
        responses: [
            new OA\Response(response: 201, description: 'Quote created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            ...QuoteData::rules(),
            'client_id' => [
                'required', 'uuid',
                Rule::exists('clients', 'id')
                    ->where('user_id', $user->accountOwnerId())
                    ->whereNull('deleted_at'),
            ],
        ]);

        $data = QuoteData::fromRequest($request);
        $quote = $this->createAction->execute($data, $user);

        return response()->json(QuoteResource::make($quote), 201);
    }

    #[OA\Put(
        path: '/api/v1/quotes/{id}',
        summary: 'Update draft quote header',
        security: [['sanctum' => []]],
        tags: ['Quotes'],
        responses: [
            new OA\Response(response: 200, description: 'Quote updated'),
            new OA\Response(response: 422, description: 'Validation error or quote not editable'),
        ]
    )]
    public function update(Request $request, Quote $quote): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            ...QuoteData::rules(),
            'client_id' => [
                'required', 'uuid',
                Rule::exists('clients', 'id')
                    ->where('user_id', $user->accountOwnerId())
                    ->whereNull('deleted_at'),
            ],
        ]);

        $data = QuoteData::fromRequest($request);
        $updated = $this->updateAction->execute($quote, $data);

        return response()->json(QuoteResource::make($updated->load(['client', 'items'])));
    }

    #[OA\Delete(
        path: '/api/v1/quotes/{id}',
        summary: 'Delete draft quote',
        security: [['sanctum' => []]],
        tags: ['Quotes'],
        responses: [
            new OA\Response(response: 204, description: 'Quote deleted'),
            new OA\Response(response: 403, description: 'Only draft quotes can be deleted'),
        ]
    )]
    public function destroy(Quote $quote): JsonResponse
    {
        $this->repository->delete($quote);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/quotes/{quote}/items',
        summary: 'Add item to quote',
        security: [['sanctum' => []]],
        tags: ['Quotes'],
        responses: [
            new OA\Response(response: 201, description: 'Item added'),
            new OA\Response(response: 422, description: 'Validation error or quote not editable'),
        ]
    )]
    public function addItem(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize('update', $quote);

        /** @var User $user */
        $user = $request->user();
        $ownerId = $user->accountOwnerId();

        $request->validate(
            QuoteItemData::rules($ownerId, $user->accountOwner()->country, $quote->issued_at->toDateString()),
        );

        $data = QuoteItemData::fromRequest($request);
        $item = $this->addItemAction->execute($quote, $data);

        return response()->json(QuoteItemResource::make($item), 201);
    }

    #[OA\Delete(
        path: '/api/v1/quotes/{quote}/items/{item}',
        summary: 'Remove item from quote',
        security: [['sanctum' => []]],
        tags: ['Quotes'],
        responses: [
            new OA\Response(response: 204, description: 'Item removed'),
            new OA\Response(response: 422, description: 'Quote not editable'),
        ]
    )]
    public function removeItem(Quote $quote, QuoteItem $item): JsonResponse
    {
        $this->authorize('update', $quote);

        $this->removeItemAction->execute($quote, $item);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/quotes/{quote}/status',
        summary: 'Manually update quote status',
        security: [['sanctum' => []]],
        tags: ['Quotes'],
        responses: [
            new OA\Response(response: 200, description: 'Quote status updated'),
            new OA\Response(response: 422, description: 'Invalid status transition'),
        ]
    )]
    public function updateStatus(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize('updateStatus', $quote);

        $request->validate([
            'status' => ['required', 'in:sent,accepted,rejected,expired'],
        ]);

        $newStatus = QuoteStatus::from($request->input('status'));
        $updated = $this->updateStatusAction->execute($quote, $newStatus);

        return response()->json(QuoteResource::make($updated));
    }

    #[OA\Post(
        path: '/api/v1/quotes/{quote}/email',
        summary: 'Email the quote PDF to the client (sends the quote first when still a draft)',
        security: [['sanctum' => []]],
        tags: ['Quotes'],
        responses: [
            new OA\Response(response: 200, description: 'Quote queued for email delivery'),
            new OA\Response(response: 422, description: 'Validation error or client without email'),
            new OA\Response(response: 429, description: 'Too many email requests'),
        ]
    )]
    public function email(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize('email', $quote);

        $request->validate(SendInvoiceEmailData::rules());

        $data = SendInvoiceEmailData::fromRequest($request);
        $updated = $this->sendEmailAction->execute($quote, $data);

        return response()->json(QuoteResource::make($updated->load(['client', 'items'])));
    }

    #[OA\Get(
        path: '/api/v1/quotes/{quote}/pdf/download',
        summary: 'Download quote PDF',
        security: [['sanctum' => []]],
        tags: ['Quotes'],
        responses: [new OA\Response(response: 200, description: 'PDF file download')]
    )]
    public function pdfDownload(Quote $quote): Response
    {
        $this->authorize('view', $quote);

        $pdf = $this->pdfService->generate($quote);
        $filename = $this->pdfService->filename($quote);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Content-Length' => strlen($pdf),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/quotes/{quote}/pdf/preview',
        summary: 'Preview quote PDF in browser',
        security: [['sanctum' => []]],
        tags: ['Quotes'],
        responses: [new OA\Response(response: 200, description: 'PDF preview')]
    )]
    public function pdfPreview(Quote $quote): Response
    {
        $this->authorize('view', $quote);

        $pdf = $this->pdfService->generate($quote);
        $filename = $this->pdfService->filename($quote);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

    #[OA\Post(
        path: '/api/v1/quotes/{quote}/public-link',
        summary: 'Create (or return the existing) public link for the quote; pass regenerate=true for a fresh token',
        security: [['sanctum' => []]],
        tags: ['Quotes'],
        responses: [
            new OA\Response(response: 200, description: 'Public link token and URL'),
            new OA\Response(response: 422, description: 'Quote is still a draft'),
        ]
    )]
    public function createPublicLink(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize('publicLink', $quote);

        $request->validate([
            'regenerate' => ['nullable', 'boolean'],
        ]);

        $updated = $this->createPublicLinkAction->execute($quote, $request->boolean('regenerate'));

        return response()->json([
            'token' => $updated->public_token,
            'url' => $updated->publicUrl(),
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/quotes/{quote}/public-link',
        summary: 'Revoke the quote public link',
        security: [['sanctum' => []]],
        tags: ['Quotes'],
        responses: [new OA\Response(response: 204, description: 'Public link revoked')]
    )]
    public function revokePublicLink(Quote $quote): JsonResponse
    {
        $this->authorize('publicLink', $quote);

        $this->revokePublicLinkAction->execute($quote);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/quotes/{quote}/convert-to-invoice',
        summary: 'Convert an accepted (or sent) quote into a draft invoice',
        security: [['sanctum' => []]],
        tags: ['Quotes'],
        responses: [
            new OA\Response(response: 201, description: 'Invoice created'),
            new OA\Response(response: 422, description: 'Invalid status or already converted'),
        ]
    )]
    public function convertToInvoice(Quote $quote): JsonResponse
    {
        $this->authorize('convert', $quote);

        $invoice = $this->convertToInvoiceAction->execute($quote);

        return response()->json(
            InvoiceResource::make($invoice->load(['client', 'items'])),
            201,
        );
    }

    #[OA\Post(
        path: '/api/v1/quotes/{quote}/convert-to-order',
        summary: 'Convert an accepted (or sent) quote into an order',
        security: [['sanctum' => []]],
        tags: ['Quotes'],
        responses: [
            new OA\Response(response: 201, description: 'Order created'),
            new OA\Response(response: 422, description: 'Invalid status or already converted'),
        ]
    )]
    public function convertToOrder(Quote $quote): JsonResponse
    {
        $this->authorize('convert', $quote);

        $order = $this->convertToOrderAction->execute($quote);

        return response()->json(
            OrderResource::make($order->load('items')),
            201,
        );
    }
}
