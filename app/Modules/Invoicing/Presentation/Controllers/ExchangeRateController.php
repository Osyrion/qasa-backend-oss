<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Presentation\Controllers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Application\DTOs\ExchangeRateData;
use App\Modules\Invoicing\Domain\Models\ExchangeRate;
use App\Modules\Shared\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Exchange Rates',
    description: 'Manual and system-sourced currency exchange rates'
)]
#[OA\Schema(
    schema: 'ExchangeRate',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'base_currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
        new OA\Property(property: 'target_currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
        new OA\Property(property: 'rate', type: 'number', format: 'float'),
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'source', type: 'string', enum: ['manual', 'ecb', 'fixer', 'cnb']),
    ]
)]
class ExchangeRateController extends Controller
{
    #[OA\Get(
        path: '/api/v1/exchange-rates',
        summary: 'List exchange rates, newest first',
        description: 'Returns Laravel\'s default paginator shape (not the app\'s usual {data, meta} Resource wrapper) — rows are top-level under `data`, pagination fields (current_page, last_page, per_page, total, ...) sit alongside it, not nested under `meta`.',
        security: [['sanctum' => []]],
        tags: ['Exchange Rates'],
        parameters: [
            new OA\Parameter(name: 'per_page', description: 'Items per page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated exchange rates',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'current_page', type: 'integer'),
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ExchangeRate')),
                        new OA\Property(property: 'first_page_url', type: 'string', nullable: true),
                        new OA\Property(property: 'from', type: 'integer', nullable: true),
                        new OA\Property(property: 'last_page', type: 'integer'),
                        new OA\Property(property: 'last_page_url', type: 'string', nullable: true),
                        new OA\Property(property: 'next_page_url', type: 'string', nullable: true),
                        new OA\Property(property: 'path', type: 'string'),
                        new OA\Property(property: 'per_page', type: 'integer'),
                        new OA\Property(property: 'prev_page_url', type: 'string', nullable: true),
                        new OA\Property(property: 'to', type: 'integer', nullable: true),
                        new OA\Property(property: 'total', type: 'integer'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $rates = ExchangeRate::query()
            ->orderBy('date', 'desc')
            ->paginate(Pagination::perPage($request));

        return response()->json($rates);
    }

    #[OA\Post(
        path: '/api/v1/exchange-rates',
        summary: 'Create or overwrite a manual rate for a currency pair and date',
        description: 'Upserts on (base_currency, target_currency, date) — a second call for the same day replaces the existing manual rate.',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['base_currency', 'target_currency', 'rate', 'date'],
                properties: [
                    new OA\Property(property: 'base_currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'target_currency', type: 'string', enum: ['CZK', 'EUR', 'USD']),
                    new OA\Property(property: 'rate', type: 'number', format: 'float', minimum: 0.000001),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                ]
            )
        ),
        tags: ['Exchange Rates'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Exchange rate created or overwritten',
                content: new OA\JsonContent(ref: '#/components/schemas/ExchangeRate')
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error, or base_currency equals target_currency'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = ExchangeRateData::fromRequest($request);

        if ($data->base_currency === $data->target_currency) {
            return response()->json(['message' => __('invoicing.exchange_currencies_must_differ')], 422);
        }

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
        path: '/api/v1/exchange-rates/{exchangeRate}',
        summary: 'Delete a manual exchange rate',
        description: 'Only rows with source=manual can be deleted — system-sourced rates (ecb, fixer, cnb) are read-only.',
        security: [['sanctum' => []]],
        tags: ['Exchange Rates'],
        parameters: [
            new OA\Parameter(name: 'exchangeRate', description: 'Exchange rate ID', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Exchange rate deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only manual rates can be deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(ExchangeRate $exchangeRate): JsonResponse
    {
        if ($exchangeRate->isSystemRate()) {
            return response()->json(['message' => __('invoicing.exchange_system_rates_not_deletable')], 403);
        }

        $exchangeRate->delete();

        return response()->json(null, 204);
    }
}
