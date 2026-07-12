<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\Actions\CreateVatRateAction;
use App\Modules\Invoicing\Application\Actions\DeleteVatRateAction;
use App\Modules\Invoicing\Application\Actions\UpdateVatRateAction;
use App\Modules\Invoicing\Application\DTOs\VatRateData;
use App\Modules\Invoicing\Domain\Models\VatRate;
use App\Modules\Invoicing\Presentation\Resources\VatRateResource;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(
    name: 'VatRates',
    description: 'Per-tenant VAT rate catalog used to validate item VAT rates'
)]
class VatRateController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly CreateVatRateAction $createAction,
        private readonly UpdateVatRateAction $updateAction,
        private readonly DeleteVatRateAction $deleteAction,
    ) {
        $this->authorizeResource(VatRate::class, 'vat_rate');
    }

    #[OA\Get(
        path: '/api/v1/vat-rates',
        summary: 'List VAT rates',
        security: [['sanctum' => []]],
        tags: ['VatRates'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'VAT rates',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/VatRate'))
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $rates = VatRate::forUser($user->accountOwnerId())
            ->orderBy('country')
            ->orderBy('rate')
            ->get();

        return VatRateResource::collection($rates);
    }

    #[OA\Get(
        path: '/api/v1/vat-rates/{id}',
        summary: 'Show VAT rate',
        security: [['sanctum' => []]],
        tags: ['VatRates'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'VAT rate', content: new OA\JsonContent(ref: '#/components/schemas/VatRate')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(VatRate $vatRate): JsonResponse
    {
        return response()->json(VatRateResource::make($vatRate));
    }

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/vat-rates',
        summary: 'Create VAT rate',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'country', 'rate'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', maxLength: 10, example: 'SK-23'),
                    new OA\Property(property: 'country', type: 'string', example: 'SK'),
                    new OA\Property(property: 'rate', type: 'number', format: 'float', example: 23),
                    new OA\Property(property: 'label', type: 'string', nullable: true),
                    new OA\Property(property: 'is_default', type: 'boolean'),
                    new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
                ]
            )
        ),
        tags: ['VatRates'],
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/VatRate')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate(VatRateData::rules($user->accountOwnerId()));

        try {
            $data = VatRateData::fromRequest($request);
            $vatRate = $this->createAction->execute($data, $user->accountOwnerId());

            return response()->json(VatRateResource::make($vatRate), 201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * @throws Throwable
     */
    #[OA\Put(
        path: '/api/v1/vat-rates/{id}',
        summary: 'Update VAT rate',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'country', 'rate'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', maxLength: 10),
                    new OA\Property(property: 'country', type: 'string'),
                    new OA\Property(property: 'rate', type: 'number', format: 'float'),
                    new OA\Property(property: 'label', type: 'string', nullable: true),
                    new OA\Property(property: 'is_default', type: 'boolean'),
                    new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
                ]
            )
        ),
        tags: ['VatRates'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(ref: '#/components/schemas/VatRate')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, VatRate $vatRate): JsonResponse
    {
        $request->validate(VatRateData::rules($vatRate->user_id, $vatRate->id));

        try {
            $data = VatRateData::fromRequest($request);
            $updated = $this->updateAction->execute($vatRate, $data);

            return response()->json(VatRateResource::make($updated));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    #[OA\Delete(
        path: '/api/v1/vat-rates/{id}',
        summary: 'Delete VAT rate',
        security: [['sanctum' => []]],
        tags: ['VatRates'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(VatRate $vatRate): JsonResponse
    {
        $this->deleteAction->execute($vatRate);

        return response()->json(null, 204);
    }
}
