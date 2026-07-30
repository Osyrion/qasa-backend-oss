<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Contracts;

use App\Modules\Invoicing\Domain\Models\VatRate;
use Illuminate\Database\Eloquent\Collection;

interface VatRateRepositoryInterface
{
    /**
     * @return Collection<int, VatRate>
     */
    public function allForUser(string $userId): Collection;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): VatRate;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(VatRate $vatRate, array $attributes): VatRate;

    public function delete(VatRate $vatRate): void;
}
