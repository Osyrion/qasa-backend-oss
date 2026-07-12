<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Models\PaymentOrder;
use Illuminate\Pagination\LengthAwarePaginator;

interface PaymentOrderRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PaymentOrder>
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator;
}
