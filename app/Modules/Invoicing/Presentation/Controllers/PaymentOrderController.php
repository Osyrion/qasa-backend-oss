<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Actions\CreatePaymentOrderAction;
use App\Modules\Invoicing\Application\Actions\DeletePaymentOrderAction;
use App\Modules\Invoicing\Application\Contracts\PaymentOrderRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\PaymentOrderData;
use App\Modules\Invoicing\Application\Services\PaymentOrderCandidatesService;
use App\Modules\Invoicing\Application\Services\PaymentOrderCsvBuilder;
use App\Modules\Invoicing\Application\Services\PaymentOrderPdfService;
use App\Modules\Invoicing\Domain\Models\BankAccount;
use App\Modules\Invoicing\Domain\Models\PaymentOrder;
use App\Modules\Invoicing\Domain\Services\AboKpcBuilder;
use App\Modules\Invoicing\Domain\Services\SepaPain001Builder;
use App\Modules\Invoicing\Presentation\Resources\PaymentOrderResource;
use App\Modules\Shared\Support\Pagination;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(
    name: 'PaymentOrders',
    description: 'Bulk payment batches for received supplier invoices (ABO/CSV/PDF export)'
)]
class PaymentOrderController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly PaymentOrderRepositoryInterface $repository,
        private readonly PaymentOrderCandidatesService $candidatesService,
        private readonly CreatePaymentOrderAction $createAction,
        private readonly DeletePaymentOrderAction $deleteAction,
        private readonly AboKpcBuilder $aboBuilder,
        private readonly SepaPain001Builder $sepaBuilder,
        private readonly PaymentOrderCsvBuilder $csvBuilder,
        private readonly PaymentOrderPdfService $pdfService,
    ) {
        $this->authorizeResource(PaymentOrder::class, 'payment_order');
    }

    #[OA\Get(
        path: '/api/v1/payment-orders',
        summary: 'List payment order batches',
        security: [['sanctum' => []]],
        tags: ['PaymentOrders'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of payment orders',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PaymentOrder')),
                        new OA\Property(property: 'meta', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = $this->repository->paginate(
            perPage: Pagination::perPage($request),
            filters: $request->only(['date_from', 'date_to', 'direction']),
        );

        return PaymentOrderResource::collection($orders);
    }

    #[OA\Get(
        path: '/api/v1/payment-orders/candidates',
        summary: 'List unpaid supplier invoices grouped for the payment-order builder',
        security: [['sanctum' => []]],
        tags: ['PaymentOrders'],
        parameters: [
            new OA\Parameter(
                name: 'bank_account_id',
                description: 'Payer account — enables the currency-match check on each row',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
            new OA\Parameter(
                name: 'hide_handed',
                description: 'Hide invoices already handed to payment',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'boolean')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Candidates grouped by ABO eligibility',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'abo_eligible', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'sepa_eligible', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'other', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Bank account not found'),
        ]
    )]
    public function candidates(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PaymentOrder::class);

        $request->validate([
            'bank_account_id' => ['nullable', 'uuid'],
            'hide_handed' => ['nullable', 'boolean'],
        ]);

        $payerAccount = null;

        if ($request->filled('bank_account_id')) {
            /** @var BankAccount $payerAccount user-scoped → foreign account is a 404 */
            $payerAccount = BankAccount::query()->findOrFail($request->string('bank_account_id')->toString());
        }

        return response()->json($this->candidatesService->candidates(
            payerAccount: $payerAccount,
            hideHanded: $request->boolean('hide_handed'),
        ));
    }

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/payment-orders',
        summary: 'Create a payment order batch and mark invoices as handed to payment',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['bank_account_id', 'due_date', 'supplier_invoice_ids'],
                properties: [
                    new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'due_date', type: 'string', format: 'date', description: 'A past date is bumped to today (due_date_adjusted flags it)'),
                    new OA\Property(property: 'constant_symbol', type: 'string', nullable: true, example: '0308'),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                    new OA\Property(property: 'supplier_invoice_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                    new OA\Property(property: 'mark_paid', type: 'boolean', description: 'Also transition the invoices to paid'),
                ]
            )
        ),
        tags: ['PaymentOrders'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Payment order created',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/PaymentOrder'),
                        new OA\Property(property: 'due_date_adjusted', type: 'boolean'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Bank account or supplier invoice not found'),
            new OA\Response(response: 422, description: 'Invoice not payable, missing account or currency mismatch'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate(PaymentOrderData::rules());

        $result = $this->createAction->execute(PaymentOrderData::fromRequest($request), $user);

        return PaymentOrderResource::make($result['order'])
            ->additional(['due_date_adjusted' => $result['due_date_adjusted']])
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/payment-orders/{id}',
        summary: 'Get payment order details with frozen rows',
        security: [['sanctum' => []]],
        tags: ['PaymentOrders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Payment order details',
                content: new OA\JsonContent(ref: '#/components/schemas/PaymentOrder')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Payment order not found'),
        ]
    )]
    public function show(PaymentOrder $paymentOrder): PaymentOrderResource
    {
        $paymentOrder->load('items');

        return PaymentOrderResource::make($paymentOrder);
    }

    /**
     * @throws Throwable
     */
    #[OA\Delete(
        path: '/api/v1/payment-orders/{id}',
        summary: 'Delete a payment order and clear the handed-to-payment flag',
        security: [['sanctum' => []]],
        tags: ['PaymentOrders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Payment order deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Payment order not found'),
        ]
    )]
    public function destroy(PaymentOrder $paymentOrder): JsonResponse
    {
        $this->deleteAction->execute($paymentOrder);

        return response()->json(null, 204);
    }

    #[OA\Get(
        path: '/api/v1/payment-orders/{payment_order}/export/{format}',
        summary: 'Download the batch as ABO (KPC), SEPA (pain.001) XML, CSV or PDF — always from the frozen snapshot',
        security: [['sanctum' => []]],
        tags: ['PaymentOrders'],
        parameters: [
            new OA\Parameter(name: 'payment_order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'format', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['abo', 'sepa', 'csv', 'pdf'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Export file'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Payment order not found'),
            new OA\Response(response: 422, description: 'ABO/SEPA is not applicable to this batch'),
        ]
    )]
    public function export(PaymentOrder $paymentOrder, string $format): Response|JsonResponse
    {
        $this->authorize('view', $paymentOrder);

        $paymentOrder->load('items');

        $date = $paymentOrder->due_date->format('Y-m-d');

        return match ($format) {
            'abo' => response($this->aboBuilder->build($paymentOrder), 200, [
                'Content-Type' => 'text/plain; charset=US-ASCII',
                'Content-Disposition' => 'attachment; filename="prikaz_'.$date.'.kpc"',
            ]),
            'sepa' => response($this->sepaBuilder->build($paymentOrder), 200, [
                'Content-Type' => 'application/xml',
                'Content-Disposition' => 'attachment; filename="prikaz_'.$date.'.xml"',
            ]),
            'csv' => response($this->csvBuilder->build($paymentOrder), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="prikaz_'.$date.'.csv"',
            ]),
            'pdf' => response($this->pdfService->generate($paymentOrder), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$this->pdfService->filename($paymentOrder).'"',
            ]),
            default => abort(404),
        };
    }
}
