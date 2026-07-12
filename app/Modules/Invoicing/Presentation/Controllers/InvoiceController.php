<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Actions\AddInvoiceItemAction;
use App\Modules\Invoicing\Application\Actions\CreateCorrectiveInvoiceAction;
use App\Modules\Invoicing\Application\Actions\CreateInvoiceAction;
use App\Modules\Invoicing\Application\Actions\GenerateInvoiceFromOrderAction;
use App\Modules\Invoicing\Application\Actions\RemindInvoiceAction;
use App\Modules\Invoicing\Application\Actions\SendInvoiceEmailAction;
use App\Modules\Invoicing\Application\Actions\UpdateInvoiceAction;
use App\Modules\Invoicing\Application\Actions\UpdateInvoiceStatusAction;
use App\Modules\Invoicing\Application\Contracts\InvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\InvoiceData;
use App\Modules\Invoicing\Application\DTOs\InvoiceItemData;
use App\Modules\Invoicing\Application\DTOs\SendInvoiceEmailData;
use App\Modules\Invoicing\Domain\Enums\InvoiceStatus;
use App\Modules\Invoicing\Domain\Enums\InvoiceType;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoiceItem;
use App\Modules\Invoicing\Presentation\Resources\InvoiceItemResource;
use App\Modules\Invoicing\Presentation\Resources\InvoiceResource;
use App\Modules\Orders\Application\Contracts\OrderRepositoryInterface;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\TimeTracking\Domain\Models\TimeEntry;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(
    name: 'Invoices',
    description: 'Invoice management endpoints'
)]
class InvoiceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CreateInvoiceAction $createAction,
        private readonly UpdateInvoiceAction $updateAction,
        private readonly AddInvoiceItemAction $addItemAction,
        private readonly GenerateInvoiceFromOrderAction $generateFromOrderAction,
        private readonly UpdateInvoiceStatusAction $updateStatusAction,
        private readonly CreateCorrectiveInvoiceAction $correctiveAction,
        private readonly SendInvoiceEmailAction $sendEmailAction,
        private readonly RemindInvoiceAction $remindAction,
    ) {
        $this->authorizeResource(Invoice::class, 'invoice');
    }

    #[OA\Get(
        path: '/api/v1/invoices',
        summary: 'List invoices',
        security: [['sanctum' => []]],
        tags: ['Invoices'],
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
                schema: new OA\Schema(type: 'string', enum: ['draft', 'sent', 'paid', 'cancelled'])
            ),
            new OA\Parameter(
                name: 'client_id',
                description: 'Filter by client',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
            new OA\Parameter(
                name: 'currency',
                description: 'Filter by currency',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['CZK', 'EUR', 'USD'])
            ),
            new OA\Parameter(
                name: 'date_from',
                description: 'Filter from date',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date')
            ),
            new OA\Parameter(
                name: 'date_to',
                description: 'Filter to date',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date')
            ),
            new OA\Parameter(
                name: 'overdue',
                description: 'Filter overdue invoices',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'boolean')
            ),
            new OA\Parameter(
                name: 'sort',
                description: 'Sort field',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'direction',
                description: 'Sort direction',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of invoices',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Invoice')),
                        new OA\Property(property: 'meta', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $invoices = $this->invoiceRepository->paginate(
            perPage: (int) $request->input('per_page', 20),
            filters: $request->only([
                'status', 'client_id', 'currency',
                'date_from', 'date_to', 'overdue',
                'sort', 'direction',
            ]),
        );

        return InvoiceResource::collection($invoices);
    }

    #[OA\Get(
        path: '/api/v1/invoices/{id}',
        summary: 'Get invoice details',
        security: [['sanctum' => []]],
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Invoice ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Invoice details',
                content: new OA\JsonContent(ref: '#/components/schemas/Invoice')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Invoice not found'),
        ]
    )]
    public function show(Invoice $invoice): InvoiceResource
    {
        $invoice->load(['client', 'items']);

        return InvoiceResource::make($invoice);
    }

    #[OA\Post(
        path: '/api/v1/invoices',
        summary: 'Create invoice',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['client_id', 'issued_at', 'due_at', 'currency'],
                properties: [
                    new OA\Property(property: 'client_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'issued_at', type: 'string', format: 'date'),
                    new OA\Property(property: 'due_at', type: 'string', format: 'date'),
                    new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'type', type: 'string', enum: ['invoice', 'proforma'], nullable: true),
                    new OA\Property(property: 'taxable_supply_at', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'variable_symbol', type: 'string', nullable: true, maxLength: 10),
                    new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'discount_percent', type: 'number', format: 'float', nullable: true),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                    new OA\Property(property: 'note_above', type: 'string', nullable: true),
                ]
            )
        ),
        tags: ['Invoices'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Invoice created',
                content: new OA\JsonContent(ref: '#/components/schemas/Invoice')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            ...InvoiceData::rules(),
            'client_id' => [
                'required', 'uuid',
                Rule::exists('clients', 'id')
                    ->where('user_id', $user->accountOwnerId())
                    ->whereNull('deleted_at'),
            ],
        ]);

        try {
            $data = InvoiceData::fromRequest($request);
            $invoice = $this->createAction->execute($data, $user);

            return response()->json(InvoiceResource::make($invoice), 201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * @throws Throwable
     */
    #[OA\Patch(
        path: '/api/v1/invoices/{id}',
        summary: 'Update draft invoice header',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['client_id', 'issued_at', 'due_at', 'currency'],
                properties: [
                    new OA\Property(property: 'client_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'issued_at', type: 'string', format: 'date'),
                    new OA\Property(property: 'taxable_supply_at', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'due_at', type: 'string', format: 'date'),
                    new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'variable_symbol', type: 'string', nullable: true, maxLength: 10),
                    new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'discount_percent', type: 'number', format: 'float', nullable: true),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                    new OA\Property(property: 'note_above', type: 'string', nullable: true),
                ]
            )
        ),
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Invoice ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Invoice updated',
                content: new OA\JsonContent(ref: '#/components/schemas/Invoice')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Invoice not found'),
            new OA\Response(response: 422, description: 'Validation error or invoice not editable'),
        ]
    )]
    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            ...InvoiceData::rules(),
            'client_id' => [
                'required', 'uuid',
                Rule::exists('clients', 'id')
                    ->where('user_id', $user->accountOwnerId())
                    ->whereNull('deleted_at'),
            ],
        ]);

        try {
            $data = InvoiceData::fromRequest($request);
            $updated = $this->updateAction->execute($invoice, $data);

            return response()->json(InvoiceResource::make($updated->load(['client', 'items'])));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    #[OA\Delete(
        path: '/api/v1/invoices/{id}',
        summary: 'Delete invoice',
        security: [['sanctum' => []]],
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Invoice ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Invoice deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only draft invoices can be deleted'),
        ]
    )]
    public function destroy(Invoice $invoice): JsonResponse
    {
        // The delete policy already restricts this to own draft invoices.
        $this->invoiceRepository->delete($invoice);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/invoices/{invoice}/items',
        summary: 'Add manual item to invoice',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['description', 'quantity', 'unit_price'],
                properties: [
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'quantity', type: 'number'),
                    new OA\Property(property: 'unit', type: 'string', default: 'ks'),
                    new OA\Property(property: 'unit_price', type: 'number'),
                    new OA\Property(property: 'vat_rate', type: 'number', default: 0),
                    new OA\Property(property: 'order_item_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'time_entry_id', type: 'string', format: 'uuid', nullable: true),
                ]
            )
        ),
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(
                name: 'invoice',
                description: 'Invoice ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Item added',
                content: new OA\JsonContent(ref: '#/components/schemas/InvoiceItem')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error or invoice not editable'),
        ]
    )]
    public function addItem(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $invoice);

        /** @var User $user */
        $user = $request->user();
        $ownerId = $user->accountOwnerId();

        // Provenance FKs must belong to the same account. time_entries carry
        // user_id directly; order_items and price_list_items are scoped through
        // their parent (orders / price_lists) so a caller cannot pin a foreign
        // account's record onto their own invoice item.
        $request->validate([
            ...InvoiceItemData::rules($ownerId, $user->accountOwner()->country, $invoice->issued_at->toDateString()),
            'time_entry_id' => [
                'nullable', 'uuid',
                Rule::exists('time_entries', 'id')->where('user_id', $ownerId),
            ],
            'order_item_id' => [
                'nullable', 'uuid',
                Rule::exists('order_items', 'id')->where(
                    fn (QueryBuilder $query): QueryBuilder => $query->whereIn(
                        'order_id',
                        DB::table('orders')->where('user_id', $ownerId)->select('id'),
                    ),
                ),
            ],
            'price_list_item_id' => [
                'nullable', 'uuid',
                Rule::exists('price_list_items', 'id')->where(
                    fn (QueryBuilder $query): QueryBuilder => $query->whereIn(
                        'price_list_id',
                        DB::table('price_lists')->where('user_id', $ownerId)->select('id'),
                    ),
                ),
            ],
        ]);

        try {
            $data = InvoiceItemData::fromRequest($request);
            $item = $this->addItemAction->execute($invoice, $data);

            return response()->json(
                InvoiceItemResource::make($item),
                201,
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    #[OA\Delete(
        path: '/api/v1/invoices/{invoice}/items/{item}',
        summary: 'Remove item from invoice',
        security: [['sanctum' => []]],
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(
                name: 'invoice',
                description: 'Invoice ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
            new OA\Parameter(
                name: 'item',
                description: 'Invoice item ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Item removed'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Invoice not editable'),
        ]
    )]
    public function removeItem(Invoice $invoice, InvoiceItem $item): JsonResponse
    {
        // The update policy restricts this to own editable (draft) invoices;
        // route-level scoped bindings guarantee the item belongs to the invoice.
        $this->authorize('update', $invoice);

        // If item was from a time entry, mark it as un-invoiced
        if ($item->time_entry_id !== null) {
            TimeEntry::find($item->time_entry_id)
                ?->update(['is_invoiced' => false]);
        }

        $item->delete();

        $invoice->load('items');
        $invoice->recalculateTotals()->save();

        return response()->json(null, 204);
    }

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/invoices/generate-from-order',
        summary: 'Generate invoice from order',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_id', 'issued_at', 'due_at', 'currency'],
                properties: [
                    new OA\Property(property: 'order_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'issued_at', type: 'string', format: 'date'),
                    new OA\Property(property: 'due_at', type: 'string', format: 'date'),
                    new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                ]
            )
        ),
        tags: ['Invoices'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Invoice generated',
                content: new OA\JsonContent(ref: '#/components/schemas/Invoice')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error or order has no billable items'),
        ]
    )]
    public function generateFromOrder(Request $request): JsonResponse
    {
        $request->validate([
            'order_id' => ['required', 'uuid', 'exists:orders,id'],
            'issued_at' => ['required', 'date'],
            'due_at' => ['required', 'date', 'after_or_equal:issued_at'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $order = $this->orderRepository->findByIdOrFail($request->input('order_id'));

        $this->authorize('view', $order);

        /** @var User $user */
        $user = $request->user();

        try {
            // The invoice is always billed to the order's client.
            $data = new InvoiceData(
                client_id: (string) $order->client_id,
                issued_at: $request->string('issued_at')->toString(),
                due_at: $request->string('due_at')->toString(),
                currency: Currency::from($request->string('currency')->toString()),
                note: $request->filled('note') ? $request->string('note')->toString() : null,
            );

            $invoice = $this->generateFromOrderAction->execute($order, $user, $data);

            $invoice->load(['client', 'items']);

            return response()->json(InvoiceResource::make($invoice), 201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/invoices/{invoice}/status',
        summary: 'Update invoice status',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['sent', 'paid', 'cancelled']),
                ]
            )
        ),
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(
                name: 'invoice',
                description: 'Invoice ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Invoice status updated',
                content: new OA\JsonContent(ref: '#/components/schemas/Invoice')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Invalid status transition'),
        ]
    )]
    public function updateStatus(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('updateStatus', $invoice);

        $request->validate([
            'status' => ['required', 'in:sent,paid,cancelled'],
        ]);

        try {
            $newStatus = InvoiceStatus::from($request->input('status'));
            $updated = $this->updateStatusAction->execute($invoice, $newStatus);

            return response()->json(InvoiceResource::make($updated));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/invoices/{invoice}/email',
        summary: 'Email the invoice PDF to the client (issues the invoice first when still a draft)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'to', type: 'string', format: 'email', nullable: true, description: 'Override recipient; defaults to the client email'),
                    new OA\Property(property: 'cc', type: 'array', items: new OA\Items(type: 'string', format: 'email'), nullable: true, maxItems: 5),
                    new OA\Property(property: 'message', type: 'string', nullable: true, maxLength: 2000, description: 'Custom message shown in the email body'),
                ]
            )
        ),
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(
                name: 'invoice',
                description: 'Invoice ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Invoice queued for email delivery',
                content: new OA\JsonContent(ref: '#/components/schemas/Invoice')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not allowed to email this invoice'),
            new OA\Response(response: 404, description: 'Invoice not found'),
            new OA\Response(response: 422, description: 'Validation error, cancelled invoice or client without email'),
            new OA\Response(response: 429, description: 'Too many email requests'),
        ]
    )]
    public function email(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('email', $invoice);

        $request->validate(SendInvoiceEmailData::rules());

        try {
            $data = SendInvoiceEmailData::fromRequest($request);
            $updated = $this->sendEmailAction->execute($invoice, $data);

            return response()->json(InvoiceResource::make($updated->load(['client', 'items'])));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/invoices/{invoice}/remind',
        summary: 'Send a payment reminder e-mail for an overdue invoice (throttled by cooldown)',
        security: [['sanctum' => []]],
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(
                name: 'invoice',
                description: 'Invoice ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Reminder queued for delivery',
                content: new OA\JsonContent(ref: '#/components/schemas/Invoice')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not allowed to remind on this invoice'),
            new OA\Response(response: 404, description: 'Invoice not found'),
            new OA\Response(response: 422, description: 'Invoice not sent, cooldown active or client without email'),
        ]
    )]
    public function remind(Invoice $invoice): JsonResponse
    {
        $this->authorize('remind', $invoice);

        try {
            $updated = $this->remindAction->execute($invoice);

            return response()->json(InvoiceResource::make($updated->load(['client', 'items'])));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/invoices/{invoice}/corrective',
        summary: 'Create a credit note (dobropis) or storno for an issued invoice',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['credit_note', 'storno']),
                ]
            )
        ),
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(
                name: 'invoice',
                description: 'Original invoice ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Corrective document created as draft',
                content: new OA\JsonContent(ref: '#/components/schemas/Invoice')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Invoice not found'),
            new OA\Response(response: 422, description: 'Invalid type or original status'),
        ]
    )]
    public function createCorrective(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);
        $this->authorize('create', Invoice::class);

        $request->validate([
            'type' => ['required', 'in:credit_note,storno'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $type = InvoiceType::from($request->input('type'));
            $corrective = $this->correctiveAction->execute($invoice, $type, $user);

            return response()->json(InvoiceResource::make($corrective->load(['client', 'items'])), 201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
