<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Infrastructure\Repositories;

use App\Modules\Invoicing\Application\Contracts\VatRateRepositoryInterface;
use App\Modules\Invoicing\Domain\Models\VatRate;
use Illuminate\Database\Eloquent\Collection;

class EloquentVatRateRepository implements VatRateRepositoryInterface
{
    public function allForUser(string $userId): Collection
    {
        return VatRate::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->orderBy('country')
            ->orderBy('rate')
            ->get();
    }

    public function create(array $attributes): VatRate
    {
        return VatRate::create($attributes);
    }

    public function update(VatRate $vatRate, array $attributes): VatRate
    {
        $vatRate->update($attributes);

        return $vatRate->refresh();
    }

    public function delete(VatRate $vatRate): void
    {
        $vatRate->delete();
    }
}
