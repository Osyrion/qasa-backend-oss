<?php

declare(strict_types=1);

namespace App\Modules\Clients\Presentation\Controllers;

use App\Modules\Clients\Application\Actions\AddContactPersonAction;
use App\Modules\Clients\Application\DTOs\ContactPersonData;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Clients\Domain\Models\ContactPerson;
use App\Modules\Clients\Presentation\Resources\ContactPersonResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(
    name: 'Contact Persons',
    description: 'Contact person management endpoints'
)]
class ContactPersonController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly AddContactPersonAction $addAction,
    ) {}

    #[OA\Get(
        path: '/api/v1/clients/{client_id}/contact-persons',
        summary: 'List contact persons for a client',
        security: [['sanctum' => []]],
        tags: ['Contact Persons'],
        parameters: [
            new OA\Parameter(
                name: 'client_id',
                description: 'Client ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of contact persons',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/ContactPerson')
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Client not found'),
        ]
    )]
    public function index(Client $client): AnonymousResourceCollection
    {
        $this->authorize('view', $client);

        return ContactPersonResource::collection(
            $client->contactPersons()->orderBy('is_primary', 'desc')->get()
        );
    }

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/clients/{client_id}/contact-persons',
        summary: 'Create contact person',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'surname'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', nullable: true, maxLength: 100),
                    new OA\Property(property: 'name', type: 'string', maxLength: 150),
                    new OA\Property(property: 'surname', type: 'string', maxLength: 150),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, maxLength: 255),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, maxLength: 30),
                    new OA\Property(property: 'role', type: 'string', nullable: true, maxLength: 100),
                    new OA\Property(property: 'is_primary', type: 'boolean', default: false),
                ]
            )
        ),
        tags: ['Contact Persons'],
        parameters: [
            new OA\Parameter(
                name: 'client_id',
                description: 'Client ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Contact person created',
                content: new OA\JsonContent(ref: '#/components/schemas/ContactPerson')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Client not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request, Client $client): JsonResponse
    {
        $this->authorize('update', $client);

        $data = ContactPersonData::fromRequest($request);
        $person = $this->addAction->execute($client, $data);

        return response()->json(ContactPersonResource::make($person), 201);
    }

    #[OA\Put(
        path: '/api/v1/clients/{client_id}/contact-persons/{id}',
        summary: 'Update contact person',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'surname'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', nullable: true, maxLength: 100),
                    new OA\Property(property: 'name', type: 'string', maxLength: 150),
                    new OA\Property(property: 'surname', type: 'string', maxLength: 150),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, maxLength: 255),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, maxLength: 30),
                    new OA\Property(property: 'role', type: 'string', nullable: true, maxLength: 100),
                    new OA\Property(property: 'is_primary', type: 'boolean'),
                ]
            )
        ),
        tags: ['Contact Persons'],
        parameters: [
            new OA\Parameter(
                name: 'client_id',
                description: 'Client ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
            new OA\Parameter(
                name: 'id',
                description: 'Contact person ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Contact person updated',
                content: new OA\JsonContent(ref: '#/components/schemas/ContactPerson')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Contact person not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, ContactPerson $contactPerson): JsonResponse
    {
        $this->authorize('update', $contactPerson->client);

        $data = ContactPersonData::fromRequest($request);

        DB::transaction(function () use ($contactPerson, $data): void {
            if ($data->is_primary) {
                /** @var Client $client */
                $client = $contactPerson->client;
                $client->contactPersons()
                    ->where('id', '!=', $contactPerson->id)
                    ->update(['is_primary' => false]);
            }

            $contactPerson->update([
                'title' => $data->title,
                'name' => $data->name,
                'surname' => $data->surname,
                'email' => $data->email,
                'phone' => $data->phone,
                'role' => $data->role,
                'is_primary' => $data->is_primary,
            ]);
        });

        /** @var ContactPerson $contactPerson */
        $fresh = $contactPerson->fresh() ?? $contactPerson;

        return response()->json(ContactPersonResource::make($fresh));
    }

    #[OA\Delete(
        path: '/api/v1/clients/{client_id}/contact-persons/{id}',
        summary: 'Delete contact person',
        security: [['sanctum' => []]],
        tags: ['Contact Persons'],
        parameters: [
            new OA\Parameter(
                name: 'client_id',
                description: 'Client ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
            new OA\Parameter(
                name: 'id',
                description: 'Contact person ID',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Contact person deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Contact person not found'),
        ]
    )]
    public function destroy(ContactPerson $contactPerson): JsonResponse
    {
        $this->authorize('update', $contactPerson->client);

        $contactPerson->delete();

        return response()->json(null, 204);
    }
}
