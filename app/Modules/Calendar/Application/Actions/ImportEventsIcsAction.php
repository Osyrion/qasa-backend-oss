<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Application\Actions;

use App\Modules\Calendar\Application\Contracts\EventRepositoryInterface;
use App\Modules\Calendar\Application\Contracts\OverlapPolicyInterface;
use App\Modules\Calendar\Application\Services\EventTimeNormalizer;
use App\Modules\Calendar\Domain\Enums\EventSource;
use App\Modules\Calendar\Infrastructure\Ics\IcsParser;
use Carbon\CarbonImmutable;

readonly class ImportEventsIcsAction
{
    public function __construct(
        private EventRepositoryInterface $repository,
        private OverlapPolicyInterface $overlapPolicy,
        private EventTimeNormalizer $normalizer,
        private IcsParser $parser,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(string $icsContent, string $userId): array
    {
        $parsed = $this->parser->parse($icsContent);

        $created = 0;
        $skipped = 0;
        $errors = $parsed['errors'];

        foreach ($parsed['events'] as $eventData) {
            try {
                /** @var CarbonImmutable $startsAt */
                $startsAt = $eventData['starts_at'];
                /** @var CarbonImmutable $endsAt */
                $endsAt = $eventData['ends_at'];
                $isAllDay = (bool) $eventData['is_all_day'];

                if ($isAllDay) {
                    [$startsAt, $endsAt] = $this->normalizer->normalizeAllDay($startsAt);
                } else {
                    $this->normalizer->assertSameDay($startsAt, $endsAt);
                    [$startsAt, $endsAt] = $this->normalizer->snapToGrid($startsAt, $endsAt);
                }

                $source = EventSource::IcsImport;
                /** @var string $externalUid */
                $externalUid = $eventData['external_uid'];

                if ($this->repository->existsExternal($userId, $source, $externalUid)) {
                    $skipped++;

                    continue;
                }

                $this->overlapPolicy->assertAllowed($userId, $startsAt, $endsAt);

                $this->repository->create([
                    'user_id' => $userId,
                    'title' => $eventData['title'],
                    'description' => $eventData['description'],
                    'location' => $eventData['location'],
                    'color' => null,
                    'is_all_day' => $isAllDay,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'source' => $source,
                    'external_uid' => $externalUid,
                ]);

                $created++;
            } catch (\Exception $e) {
                $errors[] = [
                    'uid' => $eventData['external_uid'] ?? null,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
