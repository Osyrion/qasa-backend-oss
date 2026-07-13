<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Application\Actions;

use App\Modules\Calendar\Application\Contracts\EventRepositoryInterface;
use App\Modules\Calendar\Application\Contracts\OverlapPolicyInterface;
use App\Modules\Calendar\Application\DTOs\EventData;
use App\Modules\Calendar\Application\Services\EventTimeNormalizer;
use App\Modules\Calendar\Domain\Enums\EventSource;
use App\Modules\Calendar\Domain\Models\Event;
use Carbon\CarbonImmutable;

final readonly class CreateEventAction
{
    public function __construct(
        private EventRepositoryInterface $repository,
        private OverlapPolicyInterface $overlapPolicy,
        private EventTimeNormalizer $normalizer,
    ) {}

    public function execute(EventData $data, string $userId): Event
    {
        $startsAt = CarbonImmutable::parse($data->starts_at);

        if ($data->is_all_day) {
            [$startsAt, $endsAt] = $this->normalizer->normalizeAllDay($startsAt);
        } else {
            /** @var string $rawEndsAt */
            $rawEndsAt = $data->ends_at;
            $endsAt = CarbonImmutable::parse($rawEndsAt);
        }

        $this->overlapPolicy->assertAllowed($userId, $startsAt, $endsAt);

        return $this->repository->create([
            'user_id' => $userId,
            'order_id' => $data->order_id,
            'title' => $data->title,
            'description' => $data->description,
            'location' => $data->location,
            'color' => $data->color,
            'is_all_day' => $data->is_all_day,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'source' => EventSource::Manual,
        ]);
    }
}
