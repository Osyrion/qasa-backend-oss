<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Contracts;

use App\Modules\Orders\Domain\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;

interface OrderRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginateForClient(string $clientId, int $perPage = 20, array $filters = []): LengthAwarePaginator;

    public function findById(string $id): ?Order;

    public function findByIdOrFail(string $id): Order;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Order;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Order $order, array $data): Order;

    public function delete(Order $order): void;

    public function countForUser(string $userId): int;
}
