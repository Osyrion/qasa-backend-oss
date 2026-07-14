<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Actions\CreateSupplierInvoiceAction;
use App\Modules\Invoicing\Application\Actions\DeleteSupplierInvoiceAction;
use App\Modules\Invoicing\Application\Actions\UpdateSupplierInvoiceAction;
use App\Modules\Invoicing\Application\Actions\UpdateSupplierInvoiceStatusAction;
use App\Modules\Invoicing\Application\Actions\VerifySupplierAccountAction;
use App\Modules\Invoicing\Application\Contracts\SupplierInvoiceRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\SupplierInvoiceData;
use App\Modules\Invoicing\Application\Services\SupplierPaymentQrService;
use App\Modules\Invoicing\Domain\Enums\SupplierInvoiceStatus;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Presentation\Resources\SupplierInvoiceResource;
use App\Modules\Shared\Support\Pagination;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(
    name: 'SupplierInvoices',
    description: 'Received (supplier) invoice bookkeeping endpoints'
)]
class SupplierInvoiceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly SupplierInvoiceRepositoryInterface $repository,
        private readonly CreateSupplierInvoiceAction $createAction,
        private readonly UpdateSupplierInvoiceAction $updateAction,
        private readonly UpdateSupplierInvoiceStatusAction $updateStatusAction,
        private readonly DeleteSupplierInvoiceAction $deleteAction,
        private readonly VerifySupplierAccountAction $verifyAccountAction,
        private readonly SupplierPaymentQrService $paymentQrService,
    ) {
        $this->authorizeResource(SupplierInvoice::class, 'supplier_invoice');
    }

    #[OA\Get(
        path: '/api/v1/supplier-invoices',
        summary: 'List supplier (received) invoices',
        security: [['sanctum' => []]],
        tags: ['SupplierInvoices'],
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
                schema: new OA\Schema(type: 'string', enum: ['draft', 'received', 'booked', 'paid', 'cancelled'])
            ),
            new OA\Parameter(
                name: 'client_id',
                description: 'Filter by vendor client',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
            new OA\Parameter(
                name: 'search',
                description: 'Search internal or vendor document number',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
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
                description: 'List of supplier invoices',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SupplierInvoice')),
                        new OA\Property(property: 'meta', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $supplierInvoices = $this->repository->paginate(
            perPage: Pagination::perPage($request),
            filters: $request->only([
                'status', 'client_id', 'search',
                'date_from', 'date_to',
                'sort', 'direction',
            ]),
        );

        return SupplierInvoiceResource::collection($supplierInvoices);
    }

    #[OA\Get(
        path: '/api/v1/supplier-invoices/{id}',
        summary: 'Get supplier invoice details',
        security: [['sanctum' => []]],
        tags: ['SupplierInvoices'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Supplier invoice ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Supplier invoice details',
                content: new OA\JsonContent(ref: '#/components/schemas/SupplierInvoice')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Supplier invoice not found'),
        ]
    )]
    public function show(SupplierInvoice $supplierInvoice): SupplierInvoiceResource
    {
        $supplierInvoice->load(['client', 'vatLines']);

        return SupplierInvoiceResource::make($supplierInvoice);
    }

    #[OA\Post(
        path: '/api/v1/supplier-invoices',
        summary: 'Create supplier (received) invoice',
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
        tags: ['SupplierInvoices'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Supplier invoice created',
                content: new OA\JsonContent(ref: '#/components/schemas/SupplierInvoice')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error or client is not a vendor'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            ...SupplierInvoiceData::rules($user->accountOwnerId(), $user->accountOwner()->country, $request->input('issued_at'), $request->input('vat_regime')),
            'client_id' => [
                'required', 'uuid',
                Rule::exists('clients', 'id')
                    ->where('user_id', $user->accountOwnerId())
                    ->whereNull('deleted_at'),
            ],
        ]);

        $data = SupplierInvoiceData::fromRequest($request);
        $supplierInvoice = $this->createAction->execute($data, $user);

        return response()->json(
            SupplierInvoiceResource::make($supplierInvoice->load(['client', 'vatLines'])),
            201,
        );
    }

    /**
     * @throws Throwable
     */
    #[OA\Put(
        path: '/api/v1/supplier-invoices/{id}',
        summary: 'Update draft supplier invoice',
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
        tags: ['SupplierInvoices'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Supplier invoice ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Supplier invoice updated',
                content: new OA\JsonContent(ref: '#/components/schemas/SupplierInvoice')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Supplier invoice not found'),
            new OA\Response(response: 422, description: 'Validation error, client is not a vendor, or invoice not editable'),
        ]
    )]
    public function update(Request $request, SupplierInvoice $supplierInvoice): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            ...SupplierInvoiceData::rules($user->accountOwnerId(), $user->accountOwner()->country, $request->input('issued_at'), $request->input('vat_regime')),
            'client_id' => [
                'required', 'uuid',
                Rule::exists('clients', 'id')
                    ->where('user_id', $user->accountOwnerId())
                    ->whereNull('deleted_at'),
            ],
        ]);

        $data = SupplierInvoiceData::fromRequest($request);
        $updated = $this->updateAction->execute($supplierInvoice, $data);

        return response()->json(SupplierInvoiceResource::make($updated->load(['client', 'vatLines'])));
    }

    #[OA\Delete(
        path: '/api/v1/supplier-invoices/{id}',
        summary: 'Delete draft supplier invoice',
        security: [['sanctum' => []]],
        tags: ['SupplierInvoices'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Supplier invoice ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Supplier invoice deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only draft supplier invoices can be deleted'),
            new OA\Response(response: 422, description: 'Supplier invoice not editable'),
        ]
    )]
    public function destroy(SupplierInvoice $supplierInvoice): JsonResponse
    {
        $this->deleteAction->execute($supplierInvoice);

        return response()->json(null, 204);
    }

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/supplier-invoices/{supplier_invoice}/status',
        summary: 'Update supplier invoice status',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['received', 'booked', 'paid', 'cancelled']),
                    new OA\Property(property: 'paid_at', type: 'string', format: 'date', nullable: true, description: 'Used when transitioning to paid; defaults to today'),
                ]
            )
        ),
        tags: ['SupplierInvoices'],
        parameters: [
            new OA\Parameter(
                name: 'supplier_invoice',
                description: 'Supplier invoice ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Supplier invoice status updated',
                content: new OA\JsonContent(ref: '#/components/schemas/SupplierInvoice')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Invalid status transition'),
        ]
    )]
    public function updateStatus(Request $request, SupplierInvoice $supplierInvoice): JsonResponse
    {
        $this->authorize('updateStatus', $supplierInvoice);

        $request->validate([
            'status' => ['required', 'in:received,booked,paid,cancelled'],
            'paid_at' => ['nullable', 'date'],
        ]);

        $newStatus = SupplierInvoiceStatus::from($request->input('status'));
        $updated = $this->updateStatusAction->execute(
            $supplierInvoice,
            $newStatus,
            $request->filled('paid_at') ? $request->string('paid_at')->toString() : null,
        );

        return response()->json(SupplierInvoiceResource::make($updated));
    }

    #[OA\Post(
        path: '/api/v1/supplier-invoices/{supplier_invoice}/verify-account',
        summary: 'Verify the stored vendor account against the CZ VAT payer register (CRPDPH)',
        security: [['sanctum' => []]],
        tags: ['SupplierInvoices'],
        parameters: [
            new OA\Parameter(
                name: 'supplier_invoice',
                description: 'Supplier invoice ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Verification result; on a mismatch the published accounts are listed for manual comparison',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'result', type: 'string', enum: ['published', 'unpublished', 'unreliable']),
                        new OA\Property(property: 'verified_at', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'published_accounts', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Supplier invoice not found'),
            new OA\Response(response: 422, description: 'No stored account, vendor is not a CZ VAT payer, or the register is unreachable'),
        ]
    )]
    public function verifyAccount(SupplierInvoice $supplierInvoice): JsonResponse
    {
        $this->authorize('verifyAccount', $supplierInvoice);

        return response()->json($this->verifyAccountAction->execute($supplierInvoice));
    }

    #[OA\Get(
        path: '/api/v1/supplier-invoices/{supplier_invoice}/payment-qr',
        summary: 'Payment QR for the received invoice (CZK → SPAYD, EUR → SEPA EPC)',
        security: [['sanctum' => []]],
        tags: ['SupplierInvoices'],
        parameters: [
            new OA\Parameter(
                name: 'supplier_invoice',
                description: 'Supplier invoice ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'SVG payment QR as a data URI',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data_uri', type: 'string'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Supplier invoice not found'),
            new OA\Response(response: 422, description: 'No stored account or unsupported currency'),
        ]
    )]
    public function paymentQr(SupplierInvoice $supplierInvoice): JsonResponse
    {
        $this->authorize('view', $supplierInvoice);

        return response()->json([
            'data_uri' => $this->paymentQrService->dataUri($supplierInvoice),
        ]);
    }
}
