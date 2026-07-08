<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application\Services;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Pricing\Application\Contracts\RateResolverInterface;
use App\Modules\Pricing\Application\DTOs\RateResolution;
use App\Modules\Pricing\Domain\Enums\RateLevel;
use App\Modules\Pricing\Domain\Models\Rate;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves the effective billing rate for a scope and work date.
 * Hierarchy: order > client > user (global); within a level the newest
 * valid_from <= work date wins. History is append-only, so changing a rate
 * never reprices past or in-progress (not yet invoiced) work.
 */
final readonly class RateResolver implements RateResolverInterface
{
    /**
     * Load all rate history relevant to the scope in a single query.
     */
    public function sheetFor(User $user, ?Client $client = null, ?Order $order = null): RateSheet
    {
        // Bypass the HasUserScope global scope — it depends on auth()->id(),
        // which is absent in queued jobs and CLI contexts.
        $rates = Rate::withoutGlobalScope('user')
            ->where('user_id', $user->accountOwnerId())
            ->where(function (Builder $query) use ($client, $order): void {
                $query->where('level', RateLevel::User->value);

                if ($client !== null) {
                    $query->orWhere(fn (Builder $q) => $q
                        ->where('level', RateLevel::Client->value)
                        ->where('client_id', $client->id));
                }

                if ($order !== null) {
                    $query->orWhere(fn (Builder $q) => $q
                        ->where('level', RateLevel::Order->value)
                        ->where('order_id', $order->id));
                }
            })
            ->get();

        return new RateSheet($rates);
    }

    public function resolve(User $user, ?Client $client, ?Order $order, CarbonInterface $date): ?RateResolution
    {
        return $this->sheetFor($user, $client, $order)->rateOn($date);
    }
}
