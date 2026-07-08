<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Invoicing\Application\Actions\DeletePaymentAction;
use App\Modules\Invoicing\Application\Actions\RecordPaymentAction;
use App\Modules\Invoicing\Application\DTOs\PaymentData;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use App\Modules\Invoicing\Presentation\Resources\InvoicePaymentResource;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;
use Throwable;

class InvoicePaymentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RecordPaymentAction $recordAction,
        private readonly DeletePaymentAction $deleteAction,
    ) {}

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/invoices/{invoice}/payments',
        summary: 'Record an incoming payment for an invoice',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount', 'paid_at'],
                properties: [
                    new OA\Property(property: 'amount', type: 'number', example: 1500.00),
                    new OA\Property(property: 'paid_at', type: 'string', format: 'date'),
                    new OA\Property(property: 'method', type: 'string', nullable: true, enum: ['bank_transfer', 'cash', 'card', 'other']),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
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
                description: 'Payment recorded',
                content: new OA\JsonContent(ref: '#/components/schemas/InvoicePayment')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error or invoice state does not allow payments'),
        ]
    )]
    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('recordPayment', $invoice);

        $request->validate(PaymentData::rules());

        try {
            $payment = $this->recordAction->execute($invoice, PaymentData::fromRequest($request));

            return response()->json(InvoicePaymentResource::make($payment), 201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * @throws Throwable
     */
    #[OA\Delete(
        path: '/api/v1/invoices/{invoice}/payments/{payment}',
        summary: 'Delete a mis-recorded payment',
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
                name: 'payment',
                description: 'Payment ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Payment deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Payment not found'),
        ]
    )]
    public function destroy(Invoice $invoice, InvoicePayment $payment): JsonResponse
    {
        // Scoped bindings guarantee the payment belongs to the invoice.
        $this->authorize('recordPayment', $invoice);

        $this->deleteAction->execute($invoice, $payment);

        return response()->json(null, 204);
    }
}
