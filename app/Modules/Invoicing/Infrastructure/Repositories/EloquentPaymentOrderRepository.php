<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Repositories;

use App\Modules\Invoicing\Application\Contracts\PaymentOrderRepositoryInterface;
use App\Modules\Invoicing\Domain\Models\PaymentOrder;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentPaymentOrderRepository implements PaymentOrderRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PaymentOrder>
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = PaymentOrder::query();

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to'].' 23:59:59');
        }

        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy('created_at', $direction)->paginate($perPage);
    }
}
