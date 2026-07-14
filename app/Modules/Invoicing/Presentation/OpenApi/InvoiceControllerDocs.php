<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Path-level OpenAPI operations for InvoiceController, kept separate so the
 * controller itself stays readable — L5-Swagger scans attributes across
 * app/ regardless of which class they're attached to; these methods are
 * never called.
 */
#[OA\Tag(
    name: 'Invoices',
    description: 'Invoice management endpoints'
)]
final class InvoiceControllerDocs
{
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
    public function index(): void {}

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
    public function show(): void {}

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
    public function store(): void {}

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
                    new OA\Property(property: 'expected_updated_at', type: 'string', format: 'date-time', nullable: true, description: 'Optimistic lock: the updated_at value last seen by the client. A mismatch returns 409.'),
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
            new OA\Response(response: 409, description: 'expected_updated_at does not match the invoice\'s current updated_at'),
            new OA\Response(response: 422, description: 'Validation error or invoice not editable'),
        ]
    )]
    public function update(): void {}

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
    public function destroy(): void {}

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
    public function addItem(): void {}

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
    public function removeItem(): void {}

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
    public function generateFromOrder(): void {}

    #[OA\Post(
        path: '/api/v1/invoices/{invoice}/status',
        summary: 'Update invoice status',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['issued', 'sent', 'paid', 'cancelled']),
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
    public function updateStatus(): void {}

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
    public function email(): void {}

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
    public function remind(): void {}

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
    public function createCorrective(): void {}

    #[OA\Post(
        path: '/api/v1/invoices/{invoice}/settle',
        summary: 'Settle a fully paid proforma into an ordinary tax invoice',
        security: [['sanctum' => []]],
        tags: ['Invoices'],
        parameters: [
            new OA\Parameter(
                name: 'invoice',
                description: 'Proforma invoice ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Ordinary invoice created from the settled proforma, already paid',
                content: new OA\JsonContent(ref: '#/components/schemas/Invoice')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Invoice not found'),
            new OA\Response(response: 422, description: 'Not a proforma, not fully paid yet, or already settled'),
        ]
    )]
    public function settle(): void {}

    #[OA\Post(
        path: '/api/v1/invoices/{invoice}/public-link',
        summary: 'Create (or return the existing) public link for the invoice; pass regenerate=true for a fresh token',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'regenerate', type: 'boolean', default: false),
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
                description: 'Public link token and URL',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'token', type: 'string'),
                        new OA\Property(property: 'url', type: 'string'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Invoice not found'),
            new OA\Response(response: 422, description: 'Invoice is still a draft'),
        ]
    )]
    public function createPublicLink(): void {}

    #[OA\Delete(
        path: '/api/v1/invoices/{invoice}/public-link',
        summary: 'Revoke the invoice public link',
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
            new OA\Response(response: 204, description: 'Public link revoked'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Invoice not found'),
        ]
    )]
    public function revokePublicLink(): void {}
}
