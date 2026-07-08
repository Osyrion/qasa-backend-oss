<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application\Contracts;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Orders\Domain\Models\Order;
use App\Modules\Pricing\Application\DTOs\RateResolution;
use App\Modules\Pricing\Application\Services\RateSheet;
use Carbon\CarbonInterface;

interface RateResolverInterface
{
    /**
     * Load all rate history relevant to the scope in a single query.
     */
    public function sheetFor(User $user, ?Client $client = null, ?Order $order = null): RateSheet;

    public function resolve(User $user, ?Client $client, ?Order $order, CarbonInterface $date): ?RateResolution;
}
