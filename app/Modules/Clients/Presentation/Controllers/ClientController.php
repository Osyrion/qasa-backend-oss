<?php

declare(strict_types=1);

namespace App\Modules\Clients\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Application\Actions\CreateClientAction;
use App\Modules\Clients\Application\Actions\DeleteClientAction;
use App\Modules\Clients\Application\Actions\UpdateClientAction;
use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Clients\Application\DTOs\ClientData;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Clients\Presentation\Resources\ClientResource;
use App\Modules\Shared\Support\Pagination;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(
    name: 'Clients',
    description: 'Client management endpoints'
)]
class ClientController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ClientRepositoryInterface $repository,
        private readonly CreateClientAction $createAction,
        private readonly UpdateClientAction $updateAction,
        private readonly DeleteClientAction $deleteAction,
    ) {
        $this->authorizeResource(Client::class, 'client');
    }

    #[OA\Get(
        path: '/api/v1/clients',
        summary: 'List clients',
        security: [['sanctum' => []]],
        tags: ['Clients'],
        parameters: [
            new OA\Parameter(
                name: 'per_page',
                description: 'Items per page',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 20)
            ),
            new OA\Parameter(
                name: 'role',
                description: 'Filter by role',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['customer', 'vendor', 'all'], default: 'customer')
            ),
            new OA\Parameter(
                name: 'client_type',
                description: 'Filter by client type',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['individual', 'self_employed', 'company'])
            ),
            new OA\Parameter(
                name: 'search',
                description: 'Search term',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'currency',
                description: 'Filter by currency',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['CZK', 'EUR', 'USD'])
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
                description: 'List of clients',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Client')),
                        new OA\Property(property: 'meta', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $clients = $this->repository->paginate(
            perPage: Pagination::perPage($request),
            filters: $request->only(['role', 'client_type', 'search', 'currency', 'sort', 'direction']),
        );

        return ClientResource::collection($clients);
    }

    #[OA\Get(
        path: '/api/v1/clients/{id}',
        summary: 'Get client details',
        security: [['sanctum' => []]],
        tags: ['Clients'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Client ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Client details',
                content: new OA\JsonContent(ref: '#/components/schemas/Client')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Client not found'),
        ]
    )]
    public function show(Client $client): ClientResource
    {
        $client->load('contactPersons');

        return ClientResource::make($client);
    }

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/clients',
        summary: 'Create client',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['client_type', 'country', 'currency', 'locale'],
                properties: [
                    new OA\Property(property: 'client_type', type: 'string', enum: ['individual', 'self_employed', 'company']),
                    new OA\Property(property: 'title', type: 'string', nullable: true, maxLength: 100),
                    new OA\Property(property: 'name', type: 'string', nullable: true, maxLength: 150),
                    new OA\Property(property: 'surname', type: 'string', nullable: true, maxLength: 150),
                    new OA\Property(property: 'ico', type: 'string', nullable: true, maxLength: 20),
                    new OA\Property(property: 'dic', type: 'string', nullable: true, maxLength: 20),
                    new OA\Property(property: 'vat_id', type: 'string', nullable: true, maxLength: 20),
                    new OA\Property(property: 'company_name', type: 'string', nullable: true, maxLength: 200),
                    new OA\Property(property: 'is_vat_payer', type: 'boolean'),
                    new OA\Property(property: 'is_customer', type: 'boolean', default: true),
                    new OA\Property(property: 'is_vendor', type: 'boolean', default: false),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, maxLength: 255),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, maxLength: 30),
                    new OA\Property(property: 'address', type: 'string', nullable: true, maxLength: 255),
                    new OA\Property(property: 'city', type: 'string', nullable: true, maxLength: 100),
                    new OA\Property(property: 'postal_code', type: 'string', nullable: true, maxLength: 10),
                    new OA\Property(property: 'country', type: 'string', example: 'SK', maxLength: 2),
                    new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'locale', type: 'string', example: 'sk', maxLength: 5),
                    new OA\Property(property: 'color', type: 'string', example: '#3B82F6', nullable: true, maxLength: 7),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                ]
            )
        ),
        tags: ['Clients'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Client created',
                content: new OA\JsonContent(ref: '#/components/schemas/Client')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = ClientData::validateAndCreate($request->all());
        $client = $this->createAction->execute($data, $user->accountOwnerId());

        return response()->json(ClientResource::make($client), 201);
    }

    /**
     * @throws Throwable
     */
    #[OA\Put(
        path: '/api/v1/clients/{id}',
        summary: 'Update client',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['client_type', 'country', 'currency', 'locale'],
                properties: [
                    new OA\Property(property: 'client_type', type: 'string', enum: ['individual', 'self_employed', 'company']),
                    new OA\Property(property: 'title', type: 'string', nullable: true, maxLength: 100),
                    new OA\Property(property: 'name', type: 'string', nullable: true, maxLength: 150),
                    new OA\Property(property: 'surname', type: 'string', nullable: true, maxLength: 150),
                    new OA\Property(property: 'company_name', type: 'string', nullable: true, maxLength: 200),
                    new OA\Property(property: 'ico', type: 'string', nullable: true, maxLength: 20),
                    new OA\Property(property: 'dic', type: 'string', nullable: true, maxLength: 20),
                    new OA\Property(property: 'vat_id', type: 'string', nullable: true, maxLength: 20),
                    new OA\Property(property: 'is_vat_payer', type: 'boolean'),
                    new OA\Property(property: 'is_customer', type: 'boolean', default: true),
                    new OA\Property(property: 'is_vendor', type: 'boolean', default: false),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, maxLength: 255),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, maxLength: 30),
                    new OA\Property(property: 'address', type: 'string', nullable: true, maxLength: 255),
                    new OA\Property(property: 'city', type: 'string', nullable: true, maxLength: 100),
                    new OA\Property(property: 'postal_code', type: 'string', nullable: true, maxLength: 10),
                    new OA\Property(property: 'country', type: 'string', example: 'SK', maxLength: 2),
                    new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'locale', type: 'string', example: 'sk', maxLength: 5),
                    new OA\Property(property: 'color', type: 'string', example: '#3B82F6', nullable: true, maxLength: 7),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                ]
            )
        ),
        tags: ['Clients'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Client ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Client updated',
                content: new OA\JsonContent(ref: '#/components/schemas/Client')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Client not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, Client $client): JsonResponse
    {
        $data = ClientData::validateAndCreate($request->all());
        $updated = $this->updateAction->execute($client, $data);

        return response()->json(ClientResource::make($updated));
    }

    /**
     * @throws Throwable
     */
    #[OA\Delete(
        path: '/api/v1/clients/{id}',
        summary: 'Delete client',
        security: [['sanctum' => []]],
        tags: ['Clients'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Client ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Client deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Client not found'),
            new OA\Response(response: 422, description: 'Cannot delete client with existing orders or invoices'),
        ]
    )]
    public function destroy(Client $client): JsonResponse
    {
        $this->deleteAction->execute($client);

        return response()->json(null, 204);
    }
}
