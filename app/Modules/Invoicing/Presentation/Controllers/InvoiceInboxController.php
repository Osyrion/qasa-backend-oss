<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Actions\ConvertInboxItemAction;
use App\Modules\Invoicing\Application\Actions\IgnoreInboxItemAction;
use App\Modules\Invoicing\Application\Contracts\InvoiceInboxRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\SupplierInvoiceData;
use App\Modules\Invoicing\Domain\Models\InvoiceInboxItem;
use App\Modules\Invoicing\Presentation\Resources\InvoiceInboxItemResource;
use App\Modules\Invoicing\Presentation\Resources\SupplierInvoiceResource;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

#[OA\Tag(
    name: 'InvoiceInbox',
    description: 'Review queue for supplier invoices scanned from the inbox folder'
)]
class InvoiceInboxController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly InvoiceInboxRepositoryInterface $repository,
        private readonly ConvertInboxItemAction $convertAction,
        private readonly IgnoreInboxItemAction $ignoreAction,
    ) {
        $this->authorizeResource(InvoiceInboxItem::class, 'inbox_item');
    }

    #[OA\Get(
        path: '/api/v1/invoice-inbox',
        summary: 'List invoice inbox items',
        security: [['sanctum' => []]],
        tags: ['InvoiceInbox'],
        parameters: [
            new OA\Parameter(
                name: 'per_page',
                description: 'Items per page',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 20)
            ),
            new OA\Parameter(
                name: 'status',
                description: 'Filter by status',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['pending', 'imported', 'ignored', 'failed'])
            ),
            new OA\Parameter(
                name: 'date_from',
                description: 'Filter scanned from date',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date')
            ),
            new OA\Parameter(
                name: 'date_to',
                description: 'Filter scanned to date',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of invoice inbox items',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InvoiceInboxItem')),
                        new OA\Property(property: 'meta', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $items = $this->repository->paginate(
            perPage: (int) $request->input('per_page', 20),
            filters: $request->only(['status', 'date_from', 'date_to', 'sort', 'direction']),
        );

        return InvoiceInboxItemResource::collection($items);
    }

    #[OA\Get(
        path: '/api/v1/invoice-inbox/{id}',
        summary: 'Get invoice inbox item details',
        security: [['sanctum' => []]],
        tags: ['InvoiceInbox'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Invoice inbox item ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Invoice inbox item details',
                content: new OA\JsonContent(ref: '#/components/schemas/InvoiceInboxItem')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Invoice inbox item not found'),
        ]
    )]
    public function show(InvoiceInboxItem $inboxItem): InvoiceInboxItemResource
    {
        $inboxItem->load('matchedClient');

        return InvoiceInboxItemResource::make($inboxItem);
    }

    #[OA\Get(
        path: '/api/v1/invoice-inbox/{inbox_item}/download',
        summary: 'Download the original scanned document',
        security: [['sanctum' => []]],
        tags: ['InvoiceInbox'],
        parameters: [
            new OA\Parameter(
                name: 'inbox_item',
                description: 'Invoice inbox item ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The stored document'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Invoice inbox item not found'),
        ]
    )]
    public function download(InvoiceInboxItem $inboxItem): StreamedResponse
    {
        $this->authorize('view', $inboxItem);

        return Storage::disk($inboxItem->disk)->download($inboxItem->path, $inboxItem->original_filename);
    }

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/invoice-inbox/{inbox_item}/convert',
        summary: 'Convert a reviewed inbox item into a supplier invoice',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['client_id', 'supplier_invoice_number', 'issued_at', 'currency', 'vat_lines'],
                properties: [
                    new OA\Property(property: 'client_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'supplier_invoice_number', type: 'string', maxLength: 60),
                    new OA\Property(property: 'issued_at', type: 'string', format: 'date'),
                    new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'taxable_supply_at', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'due_at', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'received_at', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'exchange_rate', type: 'number', format: 'float', nullable: true),
                    new OA\Property(property: 'variable_symbol', type: 'string', nullable: true, maxLength: 10),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                    new OA\Property(
                        property: 'vat_lines',
                        type: 'array',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'vat_rate', type: 'number', format: 'float'),
                                new OA\Property(property: 'base', type: 'number', format: 'float'),
                                new OA\Property(property: 'vat_amount', type: 'number', format: 'float'),
                                new OA\Property(property: 'sort_order', type: 'integer'),
                            ]
                        )
                    ),
                ]
            )
        ),
        tags: ['InvoiceInbox'],
        parameters: [
            new OA\Parameter(
                name: 'inbox_item',
                description: 'Invoice inbox item ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Supplier invoice created from the inbox item',
                content: new OA\JsonContent(ref: '#/components/schemas/SupplierInvoice')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error, client is not a vendor, or item already processed'),
        ]
    )]
    public function convert(Request $request, InvoiceInboxItem $inboxItem): JsonResponse
    {
        $this->authorize('convert', $inboxItem);

        /** @var User $user */
        $user = $request->user();

        $request->validate([
            ...SupplierInvoiceData::rules(),
            'client_id' => [
                'required', 'uuid',
                Rule::exists('clients', 'id')
                    ->where('user_id', $user->accountOwnerId())
                    ->whereNull('deleted_at'),
            ],
        ]);

        try {
            $data = SupplierInvoiceData::fromRequest($request);
            $supplierInvoice = $this->convertAction->execute($inboxItem, $data, $user);

            return response()->json(
                SupplierInvoiceResource::make($supplierInvoice->load(['client', 'vatLines'])),
                201,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/invoice-inbox/{inbox_item}/ignore',
        summary: 'Ignore an inbox item',
        security: [['sanctum' => []]],
        tags: ['InvoiceInbox'],
        parameters: [
            new OA\Parameter(
                name: 'inbox_item',
                description: 'Invoice inbox item ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Invoice inbox item ignored',
                content: new OA\JsonContent(ref: '#/components/schemas/InvoiceInboxItem')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Item already processed'),
        ]
    )]
    public function ignore(InvoiceInboxItem $inboxItem): JsonResponse
    {
        $this->authorize('ignore', $inboxItem);

        try {
            $updated = $this->ignoreAction->execute($inboxItem);

            return response()->json(InvoiceInboxItemResource::make($updated));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    #[OA\Delete(
        path: '/api/v1/invoice-inbox/{id}',
        summary: 'Delete an invoice inbox item',
        security: [['sanctum' => []]],
        tags: ['InvoiceInbox'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Invoice inbox item ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Invoice inbox item deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Invoice inbox item not found'),
        ]
    )]
    public function destroy(InvoiceInboxItem $inboxItem): JsonResponse
    {
        $inboxItem->delete();

        return response()->json(null, 204);
    }
}
