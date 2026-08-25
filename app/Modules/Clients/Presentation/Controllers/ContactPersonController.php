<?php

declare(strict_types=1);

namespace App\Modules\Clients\Presentation\Controllers;

use App\Modules\Clients\Application\Actions\AddContactPersonAction;
use App\Modules\Clients\Application\DTOs\ContactPersonData;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Clients\Domain\Models\ContactPerson;
use App\Modules\Clients\Presentation\Resources\ContactPersonResource;
use App\Modules\Shared\Exceptions\DomainException;
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
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContactPerson')],
                    type: 'object',
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Client not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request, Client $client): JsonResponse
    {
        $this->authorize('update', $client);

        $request->validate(ContactPersonData::getValidationRules($request->all()));

        try {
            $data = ContactPersonData::fromRequest($request);
            $person = $this->addAction->execute($client, $data);

            return ContactPersonResource::make($person)->response()->setStatusCode(201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
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
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContactPerson')],
                    type: 'object',
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Contact person not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    // NOTE (not a docblock — swagger-php publishes those as the operation
    // description): the {client} segment has to be a typed parameter even
    // though the client is reachable through $contactPerson->client. Route
    // parameters are handed to the action positionally, so an un-typehinted
    // {client} was passed into the $contactPerson slot and every call to this
    // endpoint died with a TypeError before it ran. See ContactPersonScopingTest.
    public function update(Request $request, Client $client, ContactPerson $contactPerson): JsonResponse
    {
        $this->authorize('update', $client);
        $this->ensureContactBelongsToClient($client, $contactPerson);

        $request->validate(ContactPersonData::getValidationRules($request->all()));

        $data = ContactPersonData::fromRequest($request);

        DB::transaction(function () use ($client, $contactPerson, $data): void {
            if ($data->is_primary) {
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

        return ContactPersonResource::make($fresh)->response();
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
    // Typed {client} for the same reason as update() above.
    public function destroy(Client $client, ContactPerson $contactPerson): JsonResponse
    {
        $this->authorize('update', $client);
        $this->ensureContactBelongsToClient($client, $contactPerson);

        $contactPerson->delete();

        return response()->json(null, 204);
    }

    /**
     * The nested path claims to address one client's contact person, so make
     * it true. Not a tenancy control — the Policy above and RLS behind it
     * already answer that — but without it any of the caller's client ids
     * addresses any of their contact people and the {client} segment means
     * nothing. Mirrors PriceListItemController::ensureItemBelongsToList().
     */
    private function ensureContactBelongsToClient(Client $client, ContactPerson $contactPerson): void
    {
        if ($contactPerson->client_id !== $client->id) {
            abort(404);
        }
    }
}
