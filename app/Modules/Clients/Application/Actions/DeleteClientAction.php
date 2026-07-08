<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Actions;

use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Clients\Domain\Events\ClientDeleted;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class DeleteClientAction
{
    public function __construct(
        private ClientRepositoryInterface $repository,
    ) {}

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Client $client): void
    {
        $hasActiveInvoices = $client->invoices()
            ->whereNotIn('status', ['cancelled'])
            ->exists();

        if ($hasActiveInvoices) {
            throw DomainException::because(__('clients.has_active_invoices'));
        }

        DB::transaction(function () use ($client): void {
            event(new ClientDeleted($client));
            $this->repository->delete($client);
        });
    }
}
