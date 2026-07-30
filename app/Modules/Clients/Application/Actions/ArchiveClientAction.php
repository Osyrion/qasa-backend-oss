<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Actions;

use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Clients\Domain\Models\Client;
use Illuminate\Support\Facades\Date;

readonly class ArchiveClientAction
{
    public function __construct(
        private ClientRepositoryInterface $repository,
    ) {}

    public function execute(Client $client): Client
    {
        if ($client->isArchived()) {
            return $client;
        }

        return $this->repository->update($client, ['archived_at' => Date::now()]);
    }
}
