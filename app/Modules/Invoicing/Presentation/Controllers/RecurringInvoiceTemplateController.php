<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Actions\CreateRecurringTemplateAction;
use App\Modules\Invoicing\Application\Actions\PauseRecurringTemplateAction;
use App\Modules\Invoicing\Application\Actions\ResumeRecurringTemplateAction;
use App\Modules\Invoicing\Application\Actions\UpdateRecurringTemplateAction;
use App\Modules\Invoicing\Application\Contracts\RecurringInvoiceTemplateRepositoryInterface;
use App\Modules\Invoicing\Application\DTOs\RecurringTemplateData;
use App\Modules\Invoicing\Domain\Models\RecurringInvoiceTemplate;
use App\Modules\Invoicing\Presentation\Resources\RecurringInvoiceTemplateResource;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(
    name: 'Recurring Invoice Templates',
    description: 'Templates that generate draft invoices on a schedule. Note and item description fields support period placeholders {BOM}, {EOM}, {MONTH}, {YEAR} resolved at generation time against the tax date (issue date for proforma).'
)]
class RecurringInvoiceTemplateController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RecurringInvoiceTemplateRepositoryInterface $repository,
        private readonly CreateRecurringTemplateAction $createAction,
        private readonly UpdateRecurringTemplateAction $updateAction,
        private readonly PauseRecurringTemplateAction $pauseAction,
        private readonly ResumeRecurringTemplateAction $resumeAction,
    ) {
        $this->authorizeResource(RecurringInvoiceTemplate::class, 'template');
    }

    #[OA\Get(
        path: '/api/v1/recurring-invoice-templates',
        summary: 'List recurring invoice templates',
        security: [['sanctum' => []]],
        tags: ['Recurring Invoice Templates'],
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
                schema: new OA\Schema(type: 'string', enum: ['active', 'paused', 'expired'])
            ),
            new OA\Parameter(
                name: 'client_id',
                description: 'Filter by client',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of templates',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/RecurringInvoiceTemplate')),
                        new OA\Property(property: 'meta', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $templates = $this->repository->paginate(
            perPage: (int) $request->input('per_page', 20),
            filters: $request->only(['status', 'client_id']),
        );

        return RecurringInvoiceTemplateResource::collection($templates);
    }

    #[OA\Get(
        path: '/api/v1/recurring-invoice-templates/{id}',
        summary: 'Get template details',
        security: [['sanctum' => []]],
        tags: ['Recurring Invoice Templates'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Template ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Template details',
                content: new OA\JsonContent(ref: '#/components/schemas/RecurringInvoiceTemplate')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Template not found'),
        ]
    )]
    public function show(RecurringInvoiceTemplate $template): RecurringInvoiceTemplateResource
    {
        $template->load(['client', 'items']);

        return RecurringInvoiceTemplateResource::make($template);
    }

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/recurring-invoice-templates',
        summary: 'Create recurring invoice template',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'client_id', 'period', 'first_issue_date', 'currency', 'due_days', 'items'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'client_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'period', type: 'string', enum: ['monthly', 'quarterly', 'semiannually', 'yearly']),
                    new OA\Property(property: 'day_of_month', type: 'integer', minimum: 1, maximum: 28, description: 'Ignored when last_day_of_month is true'),
                    new OA\Property(property: 'last_day_of_month', type: 'boolean', default: false),
                    new OA\Property(property: 'first_issue_date', type: 'string', format: 'date', description: 'Must not be in the past'),
                    new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'type', type: 'string', enum: ['invoice', 'proforma'], default: 'invoice'),
                    new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'due_days', type: 'integer', minimum: 0, maximum: 365),
                    new OA\Property(property: 'discount_percent', type: 'number', format: 'float', nullable: true),
                    new OA\Property(property: 'tax_date_mode', type: 'string', enum: ['issue_date', 'previous_month_end'], default: 'issue_date'),
                    new OA\Property(property: 'note_above', type: 'string', nullable: true, description: 'Supports {BOM}, {EOM}, {MONTH}, {YEAR} placeholders'),
                    new OA\Property(property: 'note_below', type: 'string', nullable: true, description: 'Supports {BOM}, {EOM}, {MONTH}, {YEAR} placeholders'),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/RecurringTemplateItem')),
                ]
            )
        ),
        tags: ['Recurring Invoice Templates'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Template created',
                content: new OA\JsonContent(ref: '#/components/schemas/RecurringInvoiceTemplate')
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
            ...RecurringTemplateData::rules(),
            'first_issue_date' => ['required', 'date', 'after_or_equal:today'],
            'client_id' => $this->clientRule($user),
        ]);

        try {
            $data = RecurringTemplateData::fromRequest($request);
            $template = $this->createAction->execute($data, $user);

            return response()->json(RecurringInvoiceTemplateResource::make($template), 201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * @throws Throwable
     */
    #[OA\Put(
        path: '/api/v1/recurring-invoice-templates/{id}',
        summary: 'Update recurring invoice template (replaces items)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/RecurringInvoiceTemplate')
        ),
        tags: ['Recurring Invoice Templates'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Template ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Template updated',
                content: new OA\JsonContent(ref: '#/components/schemas/RecurringInvoiceTemplate')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Template not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, RecurringInvoiceTemplate $template): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Past first_issue_date is rejected only when it changes on a template
        // that has not generated yet — editing an old template must not fail.
        $firstIssueDateRules = ['required', 'date'];
        if ($template->last_generated_at === null
            && $request->string('first_issue_date')->toString() !== $template->first_issue_date->toDateString()) {
            $firstIssueDateRules[] = 'after_or_equal:today';
        }

        $request->validate([
            ...RecurringTemplateData::rules(),
            'first_issue_date' => $firstIssueDateRules,
            'client_id' => $this->clientRule($user),
        ]);

        try {
            $data = RecurringTemplateData::fromRequest($request);
            $updated = $this->updateAction->execute($template, $data);

            return response()->json(RecurringInvoiceTemplateResource::make($updated->load('client')));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    #[OA\Delete(
        path: '/api/v1/recurring-invoice-templates/{id}',
        summary: 'Delete recurring invoice template',
        security: [['sanctum' => []]],
        tags: ['Recurring Invoice Templates'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Template ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Template deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Template not found'),
        ]
    )]
    public function destroy(RecurringInvoiceTemplate $template): JsonResponse
    {
        $this->repository->delete($template);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/recurring-invoice-templates/{template}/pause',
        summary: 'Pause an active template',
        security: [['sanctum' => []]],
        tags: ['Recurring Invoice Templates'],
        parameters: [
            new OA\Parameter(
                name: 'template',
                description: 'Template ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Template paused',
                content: new OA\JsonContent(ref: '#/components/schemas/RecurringInvoiceTemplate')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Template is not active'),
        ]
    )]
    public function pause(RecurringInvoiceTemplate $template): JsonResponse
    {
        $this->authorize('update', $template);

        try {
            $paused = $this->pauseAction->execute($template);

            return response()->json(RecurringInvoiceTemplateResource::make($paused));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/recurring-invoice-templates/{template}/resume',
        summary: 'Resume a paused template (skipped periods are not backfilled)',
        security: [['sanctum' => []]],
        tags: ['Recurring Invoice Templates'],
        parameters: [
            new OA\Parameter(
                name: 'template',
                description: 'Template ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Template resumed',
                content: new OA\JsonContent(ref: '#/components/schemas/RecurringInvoiceTemplate')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Template is not paused'),
        ]
    )]
    public function resume(RecurringInvoiceTemplate $template): JsonResponse
    {
        $this->authorize('update', $template);

        try {
            $resumed = $this->resumeAction->execute($template);

            return response()->json(RecurringInvoiceTemplateResource::make($resumed));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Account-scoped client existence rule.
     *
     * @return array<int, mixed>
     */
    private function clientRule(User $user): array
    {
        return [
            'required', 'uuid',
            Rule::exists('clients', 'id')
                ->where('user_id', $user->accountOwnerId())
                ->whereNull('deleted_at'),
        ];
    }
}
