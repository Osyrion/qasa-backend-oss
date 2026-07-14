<?php

declare(strict_types=1);

namespace App\Modules\Clients\Infrastructure\Repositories;

use App\Modules\Clients\Application\Contracts\ClientRepositoryInterface;
use App\Modules\Clients\Domain\Models\Client;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentClientRepository implements ClientRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Client>
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = Client::query()->with('contactPersons');

        $role = isset($filters['role']) && is_string($filters['role']) ? $filters['role'] : 'customer';

        match ($role) {
            'vendor' => $query->where('is_vendor', true),
            'all' => null,
            default => $query->where('is_customer', true),
        };

        if (! empty($filters['client_type'])) {
            $query->where('client_type', $filters['client_type']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('surname', 'ilike', "%{$search}%")
                    ->orWhere('company_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('ico', 'ilike', "%{$search}%");
            });
        }

        if (! empty($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }

        $sort = isset($filters['sort']) && is_string($filters['sort']) ? $filters['sort'] : 'created_at';
        $direction = isset($filters['direction']) && $filters['direction'] === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $direction);

        return $query->paginate($perPage);
    }

    public function findById(string $id): ?Client
    {
        /** @var Client|null */
        return Client::find($id);
    }

    public function findByIdOrFail(string $id): Client
    {
        /** @var Client */
        return Client::findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Client
    {
        /** @var Client */
        return Client::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Client $client, array $data): Client
    {
        $client->update($data);

        return $client->fresh() ?? $client;
    }

    public function delete(Client $client): void
    {
        $client->delete();
    }

    public function countForUser(string $userId): int
    {
        return Client::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->count();
    }

    public function findVendorByIco(string $userId, string $ico): ?Client
    {
        /** @var Client|null */
        return Client::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('ico', $ico)
            ->where('is_vendor', true)
            ->first();
    }
}
