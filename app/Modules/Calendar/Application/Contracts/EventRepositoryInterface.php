<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Application\Contracts;

use App\Modules\Calendar\Domain\Enums\EventSource;
use App\Modules\Calendar\Domain\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

interface EventRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, Event>
     */
    public function paginate(int $perPage = 20, ?Carbon $from = null, ?Carbon $to = null, ?string $orderId = null): LengthAwarePaginator;

    public function findById(string $id): ?Event;

    public function findByIdOrFail(string $id): Event;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Event;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Event $event, array $data): Event;

    public function delete(Event $event): void;

    public function existsExternal(string $userId, EventSource $source, string $externalUid): bool;

    /**
     * @return Collection<int, Event>
     */
    public function forExport(?Carbon $from = null, ?Carbon $to = null): Collection;

    public function purgeEndingBefore(CarbonImmutable $cutoff): int;
}
