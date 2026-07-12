<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Integrations\Application\Actions\CreateWebhookEndpointAction;
use App\Modules\Integrations\Application\Actions\SendTestWebhookAction;
use App\Modules\Integrations\Application\Actions\UpdateWebhookEndpointAction;
use App\Modules\Integrations\Application\DTOs\WebhookEndpointData;
use App\Modules\Integrations\Domain\Models\WebhookEndpoint;
use App\Modules\Integrations\Presentation\Resources\WebhookDeliveryResource;
use App\Modules\Integrations\Presentation\Resources\WebhookEndpointResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'WebhookEndpoints',
    description: 'Outbound webhook subscriptions for third-party integrations'
)]
class WebhookEndpointController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly CreateWebhookEndpointAction $createAction,
        private readonly UpdateWebhookEndpointAction $updateAction,
        private readonly SendTestWebhookAction $testAction,
    ) {
        $this->authorizeResource(WebhookEndpoint::class, 'webhook_endpoint');
    }

    #[OA\Get(
        path: '/api/v1/webhook-endpoints',
        summary: 'List webhook endpoints',
        security: [['sanctum' => []]],
        tags: ['WebhookEndpoints'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Webhook endpoints',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/WebhookEndpoint'))
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(): AnonymousResourceCollection
    {
        // HasUserScope's global scope already filters by the authenticated
        // account, same as every other module's simple listing endpoints.
        $endpoints = WebhookEndpoint::query()->orderByDesc('created_at')->get();

        return WebhookEndpointResource::collection($endpoints);
    }

    #[OA\Get(
        path: '/api/v1/webhook-endpoints/{id}',
        summary: 'Show webhook endpoint',
        security: [['sanctum' => []]],
        tags: ['WebhookEndpoints'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Webhook endpoint', content: new OA\JsonContent(ref: '#/components/schemas/WebhookEndpoint')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        return response()->json(WebhookEndpointResource::make($webhookEndpoint));
    }

    #[OA\Post(
        path: '/api/v1/webhook-endpoints',
        summary: 'Create webhook endpoint',
        description: 'The secret is returned only in this response and cannot be retrieved again.',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['url', 'events'],
                properties: [
                    new OA\Property(property: 'url', type: 'string', example: 'https://example.com/webhooks/qasa'),
                    new OA\Property(property: 'events', type: 'array', items: new OA\Items(type: 'string', example: 'invoice.paid')),
                    new OA\Property(property: 'is_active', type: 'boolean'),
                ]
            )
        ),
        tags: ['WebhookEndpoints'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created',
                content: new OA\JsonContent(allOf: [
                    new OA\Schema(ref: '#/components/schemas/WebhookEndpoint'),
                    new OA\Schema(properties: [new OA\Property(property: 'secret', type: 'string')]),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $request->validate(WebhookEndpointData::rules());
        $data = WebhookEndpointData::fromRequest($request);

        /** @var User $user */
        $user = $request->user();

        $result = $this->createAction->execute($data, $user->accountOwnerId());

        return response()->json([
            ...WebhookEndpointResource::make($result['endpoint'])->toArray($request),
            'secret' => $result['secret'],
        ], 201);
    }

    #[OA\Put(
        path: '/api/v1/webhook-endpoints/{id}',
        summary: 'Update webhook endpoint',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['url', 'events'],
                properties: [
                    new OA\Property(property: 'url', type: 'string'),
                    new OA\Property(property: 'events', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'is_active', type: 'boolean'),
                ]
            )
        ),
        tags: ['WebhookEndpoints'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/WebhookEndpoint')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $request->validate(WebhookEndpointData::rules());
        $data = WebhookEndpointData::fromRequest($request);

        $updated = $this->updateAction->execute($webhookEndpoint, $data);

        return response()->json(WebhookEndpointResource::make($updated));
    }

    #[OA\Delete(
        path: '/api/v1/webhook-endpoints/{id}',
        summary: 'Delete webhook endpoint',
        security: [['sanctum' => []]],
        tags: ['WebhookEndpoints'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $webhookEndpoint->delete();

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/webhook-endpoints/{id}/test',
        summary: 'Send a synchronous test (ping) delivery',
        security: [['sanctum' => []]],
        tags: ['WebhookEndpoints'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Test delivery result',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean'),
                    new OA\Property(property: 'status', type: 'integer', nullable: true),
                    new OA\Property(property: 'body', type: 'string', nullable: true),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function test(WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $this->authorize('view', $webhookEndpoint);

        return response()->json($this->testAction->execute($webhookEndpoint));
    }

    #[OA\Get(
        path: '/api/v1/webhook-endpoints/{id}/deliveries',
        summary: 'List delivery attempts for a webhook endpoint',
        security: [['sanctum' => []]],
        tags: ['WebhookEndpoints'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Delivery attempts',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/WebhookDelivery'))
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function deliveries(WebhookEndpoint $webhookEndpoint): AnonymousResourceCollection
    {
        $this->authorize('view', $webhookEndpoint);

        return WebhookDeliveryResource::collection(
            $webhookEndpoint->deliveries()->orderByDesc('created_at')->paginate(20)
        );
    }
}
