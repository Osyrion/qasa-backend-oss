<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Contracts;

use App\Modules\Clients\Domain\Models\Client;
use Illuminate\Pagination\LengthAwarePaginator;

interface ClientRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator;

    public function findById(string $id): ?Client;

    public function findByIdOrFail(string $id): Client;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Client;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Client $client, array $data): Client;

    public function delete(Client $client): void;

    public function countForUser(string $userId): int;
}
