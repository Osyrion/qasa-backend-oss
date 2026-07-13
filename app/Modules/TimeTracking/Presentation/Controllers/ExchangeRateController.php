<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Presentation\Controllers;

use App\Modules\Shared\Support\Pagination;
use App\Modules\TimeTracking\Application\DTOs\ExchangeRateData;
use App\Modules\TimeTracking\Domain\Models\ExchangeRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ExchangeRateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rates = ExchangeRate::query()
            ->orderBy('date', 'desc')
            ->paginate(Pagination::perPage($request));

        return response()->json($rates);
    }

    public function store(Request $request): JsonResponse
    {
        $data = ExchangeRateData::fromRequest($request);

        if ($data->base_currency === $data->target_currency) {
            return response()->json(['message' => __('time_tracking.currencies_must_differ')], 422);
        }

        $rate = ExchangeRate::updateOrCreate(
            [
                'user_id' => $request->user()->accountOwnerId(),
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
            'base_currency' => $rate->base_currency?->value,
            'target_currency' => $rate->target_currency?->value,
            'rate' => (float) $rate->rate,
            'date' => $rate->date?->toDateString(),
            'source' => $rate->source,
        ], 201);
    }

    public function destroy(ExchangeRate $exchangeRate): JsonResponse
    {
        if ($exchangeRate->isSystemRate()) {
            return response()->json(['message' => __('time_tracking.system_rates_not_deletable')], 403);
        }

        $exchangeRate->delete();

        return response()->json(null, 204);
    }
}
