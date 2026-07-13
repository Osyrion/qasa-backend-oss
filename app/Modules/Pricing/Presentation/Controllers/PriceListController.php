<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Pricing\Application\Actions\CreatePriceListAction;
use App\Modules\Pricing\Application\Actions\UpdatePriceListAction;
use App\Modules\Pricing\Application\DTOs\PriceListData;
use App\Modules\Pricing\Domain\Models\PriceList;
use App\Modules\Pricing\Presentation\Resources\PriceListResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(
    name: 'Price Lists',
    description: 'User-global price lists, segmentable by currency and country'
)]
class PriceListController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly CreatePriceListAction $createAction,
        private readonly UpdatePriceListAction $updateAction,
    ) {
        $this->authorizeResource(PriceList::class, 'price_list');
    }

    #[OA\Get(
        path: '/api/v1/price-lists',
        summary: 'List price lists',
        security: [['sanctum' => []]],
        tags: ['Price Lists'],
        parameters: [
            new OA\Parameter(name: 'currency', description: 'Filter by currency segment', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['CZK', 'EUR', 'USD'])),
            new OA\Parameter(name: 'country', description: 'Filter by country segment (ISO 3166-1 alpha-2)', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of price lists',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PriceList')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $priceLists = PriceList::query()
            ->when($request->filled('currency'), fn ($q) => $q->where('currency', $request->string('currency')->toString()))
            ->when($request->filled('country'), fn ($q) => $q->where('country', strtoupper($request->string('country')->toString())))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return PriceListResource::collection($priceLists);
    }

    #[OA\Get(
        path: '/api/v1/price-lists/{id}',
        summary: 'Get price list with items',
        security: [['sanctum' => []]],
        tags: ['Price Lists'],
        parameters: [
            new OA\Parameter(name: 'id', description: 'Price list ID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Price list details', content: new OA\JsonContent(ref: '#/components/schemas/PriceList')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Price list not found'),
        ]
    )]
    public function show(PriceList $priceList): PriceListResource
    {
        $priceList->load('items');

        return PriceListResource::make($priceList);
    }

    /**
     * @throws Throwable
     */
    #[OA\Post(
        path: '/api/v1/price-lists',
        summary: 'Create price list',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 150),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD'], nullable: true),
                    new OA\Property(property: 'country', type: 'string', example: 'SK', nullable: true, maxLength: 2),
                    new OA\Property(property: 'is_default', type: 'boolean', default: false),
                ]
            )
        ),
        tags: ['Price Lists'],
        responses: [
            new OA\Response(response: 201, description: 'Price list created', content: new OA\JsonContent(ref: '#/components/schemas/PriceList')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $request->validate(PriceListData::rules());

        /** @var User $user */
        $user = $request->user();

        $data = PriceListData::fromRequest($request);
        $priceList = $this->createAction->execute($data, $user->accountOwnerId());

        return response()->json(PriceListResource::make($priceList), 201);
    }

    /**
     * @throws Throwable
     */
    #[OA\Put(
        path: '/api/v1/price-lists/{id}',
        summary: 'Update price list',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 150),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'currency', type: 'string', enum: ['CZK', 'EUR', 'USD'], nullable: true),
                    new OA\Property(property: 'country', type: 'string', example: 'SK', nullable: true, maxLength: 2),
                    new OA\Property(property: 'is_default', type: 'boolean', default: false),
                ]
            )
        ),
        tags: ['Price Lists'],
        parameters: [
            new OA\Parameter(name: 'id', description: 'Price list ID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Price list updated', content: new OA\JsonContent(ref: '#/components/schemas/PriceList')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Price list not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, PriceList $priceList): JsonResponse
    {
        $request->validate(PriceListData::rules());

        $data = PriceListData::fromRequest($request);
        $updated = $this->updateAction->execute($priceList, $data);

        return response()->json(PriceListResource::make($updated));
    }

    #[OA\Delete(
        path: '/api/v1/price-lists/{id}',
        summary: 'Delete price list',
        description: 'Soft delete; existing order/invoice items keep their snapshotted values.',
        security: [['sanctum' => []]],
        tags: ['Price Lists'],
        parameters: [
            new OA\Parameter(name: 'id', description: 'Price list ID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Price list deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Price list not found'),
        ]
    )]
    public function destroy(PriceList $priceList): JsonResponse
    {
        $priceList->delete();

        return response()->json(null, 204);
    }
}
