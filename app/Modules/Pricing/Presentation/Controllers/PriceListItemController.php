<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Presentation\Controllers;

use App\Modules\Pricing\Application\Actions\UpsertPriceListItemAction;
use App\Modules\Pricing\Application\DTOs\PriceListItemData;
use App\Modules\Pricing\Domain\Models\PriceList;
use App\Modules\Pricing\Domain\Models\PriceListItem;
use App\Modules\Pricing\Presentation\Resources\PriceListItemResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Price List Items',
    description: 'Catalog entries of a price list'
)]
class PriceListItemController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly UpsertPriceListItemAction $upsertAction,
    ) {}

    #[OA\Get(
        path: '/api/v1/price-lists/{priceList}/items',
        summary: 'List price list items',
        security: [['sanctum' => []]],
        tags: ['Price List Items'],
        parameters: [
            new OA\Parameter(name: 'priceList', description: 'Price list ID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Items ordered by sort_order',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PriceListItem')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Price list not found'),
        ]
    )]
    public function index(PriceList $priceList): AnonymousResourceCollection
    {
        $this->authorize('view', $priceList);

        return PriceListItemResource::collection($priceList->items()->get());
    }

    #[OA\Post(
        path: '/api/v1/price-lists/{priceList}/items',
        summary: 'Add price list item',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'unit', 'unit_price'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'unit', type: 'string', example: 'hod', description: 'Known unit (ks, hod, kg, m…) or custom text', maxLength: 20),
                    new OA\Property(property: 'unit_price', type: 'number', format: 'float'),
                    new OA\Property(property: 'vat_rate', type: 'number', format: 'float', default: 0),
                    new OA\Property(property: 'is_active', type: 'boolean', default: true),
                    new OA\Property(property: 'sort_order', type: 'integer', default: 0),
                ]
            )
        ),
        tags: ['Price List Items'],
        parameters: [
            new OA\Parameter(name: 'priceList', description: 'Price list ID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Item created', content: new OA\JsonContent(ref: '#/components/schemas/PriceListItem')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Price list not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request, PriceList $priceList): JsonResponse
    {
        $this->authorize('update', $priceList);
        $request->validate(PriceListItemData::rules());

        $data = PriceListItemData::fromRequest($request);
        $item = $this->upsertAction->execute($priceList, $data);

        return response()->json(PriceListItemResource::make($item), 201);
    }

    #[OA\Put(
        path: '/api/v1/price-lists/{priceList}/items/{item}',
        summary: 'Update price list item',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'unit', 'unit_price'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'unit', type: 'string', example: 'hod', maxLength: 20),
                    new OA\Property(property: 'unit_price', type: 'number', format: 'float'),
                    new OA\Property(property: 'vat_rate', type: 'number', format: 'float', default: 0),
                    new OA\Property(property: 'is_active', type: 'boolean', default: true),
                    new OA\Property(property: 'sort_order', type: 'integer', default: 0),
                ]
            )
        ),
        tags: ['Price List Items'],
        parameters: [
            new OA\Parameter(name: 'priceList', description: 'Price list ID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item', description: 'Item ID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Item updated', content: new OA\JsonContent(ref: '#/components/schemas/PriceListItem')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, PriceList $priceList, PriceListItem $item): JsonResponse
    {
        $this->authorize('update', $priceList);
        $this->ensureItemBelongsToList($priceList, $item);
        $request->validate(PriceListItemData::rules());

        $data = PriceListItemData::fromRequest($request);
        $updated = $this->upsertAction->execute($priceList, $data, $item);

        return response()->json(PriceListItemResource::make($updated));
    }

    #[OA\Delete(
        path: '/api/v1/price-lists/{priceList}/items/{item}',
        summary: 'Delete price list item',
        description: 'Existing order/invoice items keep their snapshotted values (provenance FK is nulled).',
        security: [['sanctum' => []]],
        tags: ['Price List Items'],
        parameters: [
            new OA\Parameter(name: 'priceList', description: 'Price list ID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'item', description: 'Item ID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Item deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(PriceList $priceList, PriceListItem $item): JsonResponse
    {
        $this->authorize('update', $priceList);
        $this->ensureItemBelongsToList($priceList, $item);

        $item->delete();

        return response()->json(null, 204);
    }

    private function ensureItemBelongsToList(PriceList $priceList, PriceListItem $item): void
    {
        if ($item->price_list_id !== $priceList->id) {
            abort(404);
        }
    }
}
