<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application\Actions;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Pricing\Application\DTOs\RateData;
use App\Modules\Pricing\Domain\Enums\RateLevel;
use App\Modules\Pricing\Domain\Models\Rate;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class CreateRateAction
{
    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(RateData $data, User $user): Rate
    {
        [$client, $order] = $this->validateScope($data, $user);

        $validFrom = $data->valid_from !== null
            ? Carbon::parse($data->valid_from)->startOfDay()
            : today();

        return DB::transaction(function () use ($data, $user, $order, $validFrom): Rate {
            // Append-only history: same scope + valid_from is corrected in place,
            // any other date creates a new row and never mutates older ones.
            $rate = Rate::withoutGlobalScope('user')->updateOrCreate(
                [
                    'user_id' => $user->accountOwnerId(),
                    'level' => $data->level->value,
                    'client_id' => $data->level === RateLevel::Client ? $data->client_id : null,
                    'order_id' => $data->level === RateLevel::Order ? $data->order_id : null,
                    'valid_from' => $validFrom,
                ],
                [
                    'rate' => $data->rate,
                    'currency' => $data->currency?->value,
                    'note' => $data->note,
                ],
            );

            // Keep orders.rate as the denormalized "currently effective" value.
            if ($order !== null) {
                $current = Rate::withoutGlobalScope('user')
                    ->where('user_id', $rate->user_id)
                    ->where('level', RateLevel::Order->value)
                    ->where('order_id', $order->id)
                    ->where('valid_from', '<=', today())
                    ->orderByDesc('valid_from')
                    ->first();

                if ($current !== null) {
                    $order->forceFill(['rate' => $current->rate])->save();
                }
            }

            return $rate;
        });
    }

    /**
     * @return array{0: Client|null, 1: Order|null}
     *
     * @throws DomainException
     */
    private function validateScope(RateData $data, User $user): array
    {
        $client = null;
        $order = null;

        switch ($data->level) {
            case RateLevel::User:
                if ($data->client_id !== null || $data->order_id !== null) {
                    throw DomainException::validation(__('pricing.global_rate_cannot_have_scope'));
                }
                break;

            case RateLevel::Client:
                if ($data->client_id === null || $data->order_id !== null) {
                    throw DomainException::validation(__('pricing.client_rate_requires_client_id'));
                }

                $client = Client::withoutGlobalScope('user')
                    ->where('user_id', $user->accountOwnerId())
                    ->find($data->client_id);

                if ($client === null) {
                    throw DomainException::validation(__('pricing.client_not_found'));
                }
                break;

            case RateLevel::Order:
                if ($data->order_id === null || $data->client_id !== null) {
                    throw DomainException::validation(__('pricing.order_rate_requires_order_id'));
                }

                $order = Order::withoutGlobalScope('user')
                    ->where('user_id', $user->accountOwnerId())
                    ->find($data->order_id);

                if ($order === null) {
                    throw DomainException::validation(__('pricing.order_not_found'));
                }

                if ($order->isPersonal()) {
                    throw DomainException::because(__('pricing.personal_order_cannot_have_rate'));
                }
                break;
        }

        $this->validateCurrency($data, $user, $client, $order);

        return [$client, $order];
    }

    /**
     * Cross-currency conversion is not supported (v1) — an explicit rate
     * currency must match the effective currency of its scope.
     *
     * @throws DomainException
     */
    private function validateCurrency(RateData $data, User $user, ?Client $client, ?Order $order): void
    {
        if ($data->currency === null) {
            return;
        }

        $effective = match ($data->level) {
            RateLevel::User => $user->default_currency,
            RateLevel::Client => $client !== null ? $client->currency : $user->default_currency,
            RateLevel::Order => $order !== null ? $order->effectiveCurrency() : $user->default_currency,
        };

        if ($data->currency !== $effective) {
            throw DomainException::validation(__('pricing.currency_mismatch', [
                'rate_currency' => $data->currency->value,
                'scope_currency' => $effective->value,
            ]));
        }
    }
}
