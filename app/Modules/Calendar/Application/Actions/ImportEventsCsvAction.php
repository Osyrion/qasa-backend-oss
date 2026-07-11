<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Application\Actions;

use App\Modules\Calendar\Application\Contracts\EventCsvParserInterface;
use App\Modules\Calendar\Application\Contracts\EventRepositoryInterface;
use App\Modules\Calendar\Application\Contracts\OverlapPolicyInterface;
use App\Modules\Calendar\Application\Services\EventTimeNormalizer;
use App\Modules\Calendar\Domain\Enums\EventSource;
use Carbon\CarbonImmutable;
use League\Csv\Reader;
use SplFileObject;

readonly class ImportEventsCsvAction
{
    /**
     * @param  array<EventCsvParserInterface>  $parsers
     */
    public function __construct(
        private EventRepositoryInterface $repository,
        private OverlapPolicyInterface $overlapPolicy,
        private EventTimeNormalizer $normalizer,
        private array $parsers,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(SplFileObject $file, string $userId): array
    {
        $csv = Reader::createFromFileObject($file);
        $csv->setDelimiter(';');
        $csv->setHeaderOffset(0);

        $headers = $csv->getHeader();
        $parser = $this->findParser($headers);

        if (! $parser) {
            throw new \InvalidArgumentException(__('calendar.import.unsupported_format'));
        }

        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach ($csv as $row) {
            try {
                $eventData = $parser->parseRow($row);

                $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', (string) $eventData['starts_at']);
                $endsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', (string) $eventData['ends_at']);

                if (! $startsAt instanceof CarbonImmutable || ! $endsAt instanceof CarbonImmutable) {
                    throw new \InvalidArgumentException(__('calendar.import.unsupported_format'));
                }

                $isAllDay = (bool) $eventData['is_all_day'];

                if ($isAllDay) {
                    [$startsAt, $endsAt] = $this->normalizer->normalizeAllDay($startsAt);
                } else {
                    $this->normalizer->assertSameDay($startsAt, $endsAt);
                    [$startsAt, $endsAt] = $this->normalizer->snapToGrid($startsAt, $endsAt);
                }

                /** @var EventSource $source */
                $source = $eventData['source'];
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
                    'color' => $eventData['color'],
                    'is_all_day' => $isAllDay,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'source' => $source,
                    'external_uid' => $externalUid,
                ]);

                $created++;
            } catch (\Exception $e) {
                $errors[] = [
                    'row' => $row,
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

    /**
     * @param  array<string>  $headers
     */
    private function findParser(array $headers): ?object
    {
        return array_find($this->parsers, fn ($parser) => $parser->canHandle($headers));
    }
}
