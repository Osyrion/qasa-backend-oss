<?php

declare(strict_types=1);

namespace App\Modules\Clients\Domain\Events;

use App\Modules\Clients\Domain\Models\Client;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClientCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Client $client,
    ) {}
}
