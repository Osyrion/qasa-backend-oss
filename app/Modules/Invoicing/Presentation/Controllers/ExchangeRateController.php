<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\DTOs\ExchangeRateData;
use App\Modules\Invoicing\Domain\Models\ExchangeRate;
use App\Modules\Shared\Support\Pagination;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

class ExchangeRateController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/v1/exchange-rates',
        summary: 'List exchange rates',
        security: [['sanctum' => []]],
        tags: ['ExchangeRates'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated exchange rates',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'base_currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                            new OA\Property(property: 'target_currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                            new OA\Property(property: 'rate', type: 'number', format: 'float'),
                            new OA\Property(property: 'date', type: 'string', format: 'date'),
                            new OA\Property(property: 'source', type: 'string'),
                        ]
                    )),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ExchangeRate::class);

        $rates = ExchangeRate::query()
            ->orderBy('date', 'desc')
            ->paginate(Pagination::perPage($request));

        return response()->json($rates);
    }

    #[OA\Post(
        path: '/api/v1/exchange-rates',
        summary: 'Create or update an exchange rate',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['base_currency', 'target_currency', 'rate', 'date'],
                properties: [
                    new OA\Property(property: 'base_currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'target_currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'rate', type: 'number', format: 'float'),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                ]
            )
        ),
        tags: ['ExchangeRates'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created or updated exchange rate',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'base_currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'target_currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'rate', type: 'number', format: 'float'),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'source', type: 'string'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error or same currency'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ExchangeRate::class);

        $data = ExchangeRateData::fromRequest($request);

        if ($data->base_currency === $data->target_currency) {
            return response()->json(['message' => __('invoicing.currencies_must_differ')], 422);
        }

        /** @var User $user */
        $user = $request->user();

        $rate = ExchangeRate::updateOrCreate(
            [
                'user_id' => $user->accountOwnerId(),
                'base_currency' => $data->base_currency->value,
                'target_currency' => $data->target_currency->value,
                'date' => $data->date,
            ],
            [
                'rate' => $data->rate,
                'source' => 'manual',
            ],
        );

        return response()->json([
            'id' => $rate->id,
            'base_currency' => $rate->base_currency->value,
            'target_currency' => $rate->target_currency->value,
            'rate' => (float) $rate->rate,
            'date' => $rate->date->toDateString(),
            'source' => $rate->source,
        ], 201);
    }

    #[OA\Delete(
        path: '/api/v1/exchange-rates/{exchange_rate}',
        summary: 'Delete an exchange rate',
        security: [['sanctum' => []]],
        tags: ['ExchangeRates'],
        parameters: [
            new OA\Parameter(name: 'exchange_rate', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'System rate cannot be deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(ExchangeRate $exchangeRate): JsonResponse
    {
        // Ability first, business rule second: the policy lets a system rate
        // through precisely so the specific message below is what a caller
        // who *may* delete rates sees, instead of a bare 403.
        $this->authorize('delete', $exchangeRate);

        if ($exchangeRate->isSystemRate()) {
            return response()->json(['message' => __('invoicing.system_rates_not_deletable')], 403);
        }

        $exchangeRate->delete();

        return response()->json(null, 204);
    }
}
